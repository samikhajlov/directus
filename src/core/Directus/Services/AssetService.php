<?php

namespace Directus\Services;

use Exception;
use Zend\Db\Sql\Select;
use Directus\Util\ArrayUtils;
use Directus\Filesystem\Thumbnail;
use Directus\Filesystem\Filesystem;
use Directus\Application\Container;
use Directus\Database\Schema\SchemaManager;
use Directus\Exception\UnprocessableEntityException;
use Directus\Database\Exception\ItemNotFoundException;
use function Directus\get_directus_thumbnail_settings;
use Intervention\Image\ImageManagerStatic as Image;
use Directus\Util\DateTimeUtils;

class AssetService extends AbstractService
{

    /**
     * @var string
     */
    protected $collection;

    /**
     * @var string
     */
    protected $thumbnailParams;

    /**
     * @var string
     */
    protected $thumbnailDir;

    /**
     * @var string
     */
    protected $fileName;

     /**
     * @var string
     */
    protected $fileNameDownload;

    /**
     * Main Filesystem
     *
     * @var Filesystem
     */
    private $filesystem;

    /**
     * Thumbnail Filesystem
     *
     * @var Filesystem
     */
    private $filesystemThumb;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->collection = SchemaManager::COLLECTION_FILES;
        $this->filesystem =$this->container->get('filesystem');
        $this->filesystemThumb =$this->container->get('filesystem_thumb');
        $this->config = get_directus_thumbnail_settings();
        $this->thumbnailParams = [];
    }

    public function getAsset($fileHashId, array $params = [])
    {
        $tableGateway = $this->createTableGateway($this->collection);
        $select = new Select($this->collection);
        $select->columns(['filename_disk', 'filename_download', 'id', 'type']);
        $select->where(['private_hash' => $fileHashId]);
        $select->limit(1);
        $result = $tableGateway->ignoreFilters()->selectWith($select);

        if ($result->count() == 0) {
            throw new ItemNotFoundException();
        }

        $file = $result->current()->toArray();

        // Get original image
        if (count($params) == 0) {
            $lastModified = $this->filesystem->getAdapter()->getTimestamp($file['filename_disk']);
            $lastModified = new DateTimeUtils(date('c', $lastModified));

            $img = $this->filesystem->read($file['filename_disk']);
            $result = [];
            $result['last_modified'] = $lastModified->toRFC2616Format();
            $result['mimeType'] = $file['type'];
            $result['file'] = isset($img) && $img ? $img : null;
            $result['filename'] = $file['filename_disk'];
            $result['filename_download'] = $file['filename_download'];

            return $result;
        }else{

            $this->fileName = $file['filename_disk'];
            $this->fileNameDownload = $file['filename_download'];
            try {
                return $this->getThumbnail($params);
            }
            catch(Exception $e)
            {
                throw new UnprocessableEntityException(sprintf($e->getMessage()));
            }
        }
    }

    public function getThumbnail($params)
    {
        $this->thumbnailParams=$params;

        $this->validateThumbnailParams($params);

        if (! $this->filesystem->exists($this->fileName)) {
            throw new Exception($this->fileName . ' does not exist.');
        }

        $this->thumbnailDir = 'w' . $this->thumbnailParams['width'] . ',h' . $this->thumbnailParams['height'] .
                              ',f' . $this->thumbnailParams['fit'] . ',q' . $this->thumbnailParams['quality'];

        try {
            $image = $this->getExistingThumbnail();

            if (!$image) {
                switch ($this->thumbnailParams['fit']) {
                    case 'contain':
                        $image = $this->contain();
                        break;
                    case 'crop':
                    default:
                        $image = $this->crop();
                }
            }

            $result['mimeType'] = $this->getThumbnailMimeType($this->thumbnailDir, $this->fileName);
            $result['last_modified'] = $this->getThumbnailLastModified($this->thumbnailDir, $this->fileName);
            $result['file'] = $image;
            $result['filename_download'] = $this->fileNameDownload;
            return $result;
        }
        catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * validate params and file extensions and return it
     *
     * @param string $thumbnailUrlPath
     * @param array $params
     * @throws Exception
     * @return array
     */
    public function validateThumbnailParams($params)
    {
        // If the user provided the key param
        $usesKey = isset($params['key']);

        // The user provided whitelist. If this is empty, any combination of the params is allowed
        $userWhitelist = json_decode(ArrayUtils::get($this->getConfig(), 'asset_whitelist'), true);

        // The system native thumbnails that can't be changed
        $systemWhitelist = json_decode(ArrayUtils::get($this->getConfig(), 'asset_whitelist_system'), true);

        // If the whitelist is set and therefore mandatory
        $whitelistEnabled = empty($userWhitelist) == false;

        // All available sizes in the system
        $allSizes = $whitelistEnabled ? array_merge($systemWhitelist, $userWhitelist) : $systemWhitelist;

        if ($usesKey) {
            // Retrieve the sizes by the key that's passed
            $exists = false;

            foreach($allSizes as $key => $value) {
                if ($value['key'] == $params['key']) {
                    $exists = true;
                    $params = [
                        'w'=> $value['width'],
                        'h' => $value['height'],
                        'q' => $value['quality'],
                        'f' => $value['fit'],
                        'key' => $params['key']
                    ];
                }
            }

            if ($exists == false) {
                throw new Exception(sprintf("Key doesn't exist."));
            }

            $this->thumbnailParams['key']= filter_var($params['key'], FILTER_SANITIZE_STRING);
        }

        // We require all the params to be there when the key param isn't used
        if ($usesKey == false) {
            $this->validate(
                [
                    'w' => isset($params['w']) ? $params['w'] : '',
                    'h' => isset($params['h']) ? $params['h'] : '',
                    'q' => isset($params['q']) ? $params['q'] : '',
                    'f' => isset($params['f']) ? $params['f'] : ''
                ],
                [
                    'w' => 'required|numeric',
                    'h' => 'required|numeric',
                    'q' => 'required|numeric',
                    'f' => 'required'
                ]
            );
        }

        // If the user didn't provide a key, and the whitelist items are required,
        // verify if the passed keys match one of the predefined items

        if (!$usesKey && $whitelistEnabled) {
            $exists = false;

            foreach($allSizes as $key => $value) {
                if (
                    $value['width'] == $params['w'] &&
                    $value['height'] == $params['h'] &&
                    $value['fit'] == $params['f'] &&
                    $value['quality'] == $params['q']
                ) {
                    $exists = true;
                }
            }

            if (!$exists) {
                throw new Exception(sprintf("The params don't match the asset whitelist."));
            }
        }

        $this->thumbnailParams['fit']= filter_var($params['f'], FILTER_SANITIZE_STRING);
        $this->thumbnailParams['height']= filter_var($params['h'], FILTER_SANITIZE_STRING);
        $this->thumbnailParams['quality']= filter_var($params['q'], FILTER_SANITIZE_STRING);
        $this->thumbnailParams['width']= filter_var($params['w'], FILTER_SANITIZE_STRING);

        $ext = pathinfo($this->fileName, PATHINFO_EXTENSION);
        $name = pathinfo($this->fileName, PATHINFO_FILENAME);

        if (!$this->isSupportedFileExtension($ext)) {
            throw new Exception('Invalid file extension.');
        }

        $this->thumbnailParams['format'] = strtolower(ArrayUtils::get($params, 'format') ?: $ext);

        if (!$this->isSupportedFileExtension($this->thumbnailParams['format'])) {
            throw new Exception('Invalid file format.');
        }

        if (
            $this->thumbnailParams['format'] !== NULL &&
            strtolower($ext) !== $this->thumbnailParams['format'] &&
            !(
                ($this->thumbnailParams['format'] == 'jpeg' || $this->thumbnailParams['format'] == 'jpg') &&
                (strtolower($ext) == 'jpeg' || strtolower($ext) == 'jpg')
            )
        ) {
            $this->thumbnailParams['thumbnailFileName'] = $name . '.' .$this->thumbnailParams['format'];
        } else {
            $this->thumbnailParams['thumbnailFileName'] = $this->fileName;
        }
    }

    /**
     * Check if given file extension is supported
     *
     * @param int $ext
     * @return boolean
     */
    public function isSupportedFileExtension($ext)
    {
        return in_array(strtolower($ext), $this->getSupportedFileExtensions());
    }

    /**
     * Return supported image file types
     *
     * @return array
     */
    public function getSupportedFileExtensions()
    {
        return Thumbnail::getFormatsSupported();
    }

    /**
     * Merge file and thumbnailer config settings and return
     *
     * @throws Exception
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Return thumbnail as data
     *
     * @throws Exception
     * @return string|null
    */
    public function getExistingThumbnail()
    {
        try {
            if( $this->filesystemThumb->exists($this->thumbnailDir . '/' . $this->thumbnailParams['thumbnailFileName']) ) {
                $img = $this->filesystemThumb->read($this->thumbnailDir . '/' . $this->thumbnailParams['thumbnailFileName']);
            }
            return isset($img) && $img ? $img : null;
        }
        catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Replace PDF files with a JPG thumbnail
     * @throws Exception
     * @return string image content
     */
    public function load () {
        $content = $this->filesystem->read($this->fileName);
        $ext = pathinfo($this->fileName, PATHINFO_EXTENSION);
        if (Thumbnail::isNonImageFormatSupported($ext)) {
            $content = Thumbnail::createImageFromNonImage($content);
        }
        return Image::make($content);
    }

    /**
     * Create thumbnail from image and `contain`
     * http://image.intervention.io/api/resize
     * https://css-tricks.com/almanac/properties/o/object-fit/
     *
     * @throws Exception
     * @return string
     */
    public function contain()
    {
        try {
            $img = $this->load();
            $img->resize($this->thumbnailParams['width'], $this->thumbnailParams['height'], function ($constraint) {
                $constraint->aspectRatio();
            });
            $encodedImg = (string) $img->encode($this->thumbnailParams['format'], ($this->thumbnailParams['quality'] ? $this->thumbnailParams['quality'] : null));
            $this->filesystemThumb->write($this->thumbnailDir . '/' . $this->thumbnailParams['thumbnailFileName'], $encodedImg);

            return $encodedImg;
        }
        catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Create thumbnail from image and `crop`
     * http://image.intervention.io/api/fit
     * https://css-tricks.com/almanac/properties/o/object-fit/
     *
     * @throws Exception
     * @return string
     */
    public function crop()
    {
        try {
            $img = $this->load();
            $img->fit($this->thumbnailParams['width'],$this->thumbnailParams['height'], function($constraint){});
            $encodedImg = (string) $img->encode($this->thumbnailParams['format'], ($this->thumbnailParams['quality'] ? $this->thumbnailParams['quality'] : null));
            $this->filesystemThumb->write($this->thumbnailDir . '/' . $this->thumbnailParams['thumbnailFileName'], $encodedImg);

            return $encodedImg;
        }
        catch (Exception $e) {
            throw $e;
        }
    }

    public function getDefaultThumbnail()
    {
        $basePath=$this->container->get('path_base');
        $filePath = ArrayUtils::get($this->config, 'thumbnail_not_found_location');
        if (is_string($filePath) && !empty($filePath) && $filePath[0] !== '/') {
            $filePath = $basePath . '/' . $filePath;
        }

        if (file_exists($filePath)) {
            $result['mimeType'] = image_type_to_mime_type(exif_imagetype($filePath));
            $result['file']=file_get_contents($filePath);
            $filename = pathinfo($filePath);
            $result['filename']= $filename['basename'];
            return $result;
        } else {
             return http_response_code(404);
        }
    }

    public function getThumbnailMimeType($path, $fileName)
    {
        try {
            if($this->filesystemThumb->exists($path . '/' . $fileName) ) {
                if(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) == 'webp') {
                    return 'image/webp';
                }
                $img = Image::make($this->filesystemThumb->read($path. '/' . $fileName));
                return $img->mime();
            }
            return 'application/octet-stream';
        }

        catch (Exception $e) {
            throw $e;
        }
    }

    public function getThumbnailLastModified($path, $fileName)
    {
        try {
            $lastModified = $this->filesystemThumb->getAdapter()->getTimestamp($path . '/' . $fileName);
            $lastModified = new DateTimeUtils(date('c', $lastModified));
            return $lastModified->toRFC2616Format();
        }

        catch (Exception $e) {
            throw $e;
        }
    }
}
