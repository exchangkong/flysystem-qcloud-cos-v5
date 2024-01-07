<?php

namespace Freyo\Flysystem\QcloudCOSv5;

use Carbon\Carbon;
use DateTimeInterface;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Config;
use Qcloud\Cos\Client;
use Qcloud\Cos\Exception\ServiceResponseException;

/**
 * Class Adapter.
 */
class Adapter implements FilesystemAdapter
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var array
     */
    protected $config = [];

    /**
     * @var array
     */
    protected $regionMap = [
        'cn-east'      => 'ap-shanghai',
        'cn-sorth'     => 'ap-guangzhou',
        'cn-north'     => 'ap-beijing-1',
        'cn-south-2'   => 'ap-guangzhou-2',
        'cn-southwest' => 'ap-chengdu',
        'sg'           => 'ap-singapore',
        'tj'           => 'ap-beijing-1',
        'bj'           => 'ap-beijing',
        'sh'           => 'ap-shanghai',
        'gz'           => 'ap-guangzhou',
        'cd'           => 'ap-chengdu',
        'sgp'          => 'ap-singapore',
    ];

    /**
     * Adapter constructor.
     *
     * @param Client $client
     * @param array  $config
     */
    public function __construct(Client $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;

        $this->setPathPrefix($config['cdn']);
    }

    /**
     * @return string
     */
    public function getBucketWithAppId()
    {
        return $this->getBucket().'-'.$this->getAppId();
    }

    /**
     * @return string
     */
    public function getBucket()
    {
        return preg_replace(
            "/-{$this->getAppId()}$/",
            '',
            $this->config['bucket']
        );
    }

    /**
     * @return string
     */
    public function getAppId()
    {
        return $this->config['credentials']['appId'];
    }

    /**
     * @return string
     */
    public function getRegion()
    {
        return array_key_exists($this->config['region'], $this->regionMap)
            ? $this->regionMap[$this->config['region']] : $this->config['region'];
    }

    /**
     * @param $path
     *
     * @return string
     */
    public function getSourcePath($path)
    {
        return sprintf('%s.cos.%s.myqcloud.com/%s',
            $this->getBucketWithAppId(), $this->getRegion(), $path
        );
    }

    /**
     * @param $path
     *
     * @return string
     */
    public function getPicturePath($path)
    {
        return sprintf('%s.pic.%s.myqcloud.com/%s',
            $this->getBucketWithAppId(), $this->getRegion(), $path
        );
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public function getUrl($path)
    {
        if ($this->config['cdn']) {
            return $this->applyPathPrefix($path);
        }

        $options = [
            'Scheme' => isset($this->config['scheme']) ? $this->config['scheme'] : 'http',
        ];

        /** @var \GuzzleHttp\Psr7\Uri $objectUrl */
        $objectUrl = $this->client->getObjectUrl(
            $this->getBucketWithAppId(), $path, "+30 minutes", $options
        );

        return (string) $objectUrl;
    }

    /**
     * @param string             $path
     * @param \DateTimeInterface $expiration
     * @param array              $options
     *
     * @return string
     */
    public function getTemporaryUrl($path, DateTimeInterface $expiration, array $options = [])
    {
        $options = array_merge(
            $options,
            ['Scheme' => isset($this->config['scheme']) ? $this->config['scheme'] : 'http']
        );

        /** @var \GuzzleHttp\Psr7\Uri $objectUrl */
        $objectUrl = $this->client->getObjectUrl(
            $this->getBucketWithAppId(), $path, $expiration->format('c'), $options
        );

        return (string) $objectUrl;
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     *
     * @return array|false
     */
    public function write(string $path, $contents, Config $config): void
    {
        try {
            $this->client->upload(
                $this->getBucketWithAppId(),
                $path,
                $contents,
                $this->prepareUploadConfig($config)
            );
        } catch (ServiceResponseException $e) {
            echo $e.PHP_EOL;
        }
    }

    /**
     * @param string $path
     * @param resource $contents
     * @param Config   $config
     *
     * @return array|false
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $this->client->upload(
                $this->getBucketWithAppId(),
                $path,
                stream_get_contents($contents, -1, 0),
                $this->prepareUploadConfig($config)
            );
        } catch (ServiceResponseException $e) {
            echo $e.PHP_EOL;
        }
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     *
     * @return array|false
     */
    public function update(string $path, string $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * @param string $path
     * @param resource $resource
     * @param Config   $config
     *
     * @return array|false
     */
    public function updateStream(string $path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        try {
            if ($result = $this->copy($path, $newpath)) {
                $this->delete($path);
            }

            return $result;
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy(string $path, string $newpath, Config $config): void
    {
        try {
            $this->client->copyObject([
                'Bucket'     => $this->getBucketWithAppId(),
                'Key'        => $newpath,
                'CopySource' => $this->getSourcePath($path),
            ]);
        } catch (ServiceResponseException $e) {
            echo $e.PHP_EOL;
        }
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function delete($path): void
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $path,
            ]);
        } catch (ServiceResponseException $e) {
            echo $e.PHP_EOL;
        }
    }

    /**
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        try {
            return (bool) $this->client->deleteObject([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $dirname.'/',
            ]);
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * @param string $dirname
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        try {
            return $this->client->putObject([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $dirname.'/',
                'Body'   => '',
            ]);
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * @param string $path
     * @param string $visibility
     *
     * @return bool
     */
    public function setVisibility($path, $visibility): void
    {
        try {
            $this->client->putObjectAcl([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $path,
                'ACL'    => $this->normalizeVisibility($visibility),
            ]);
        } catch (ServiceResponseException $e) {
            echo $e.PHP_EOL;
        }
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function has($path)
    {
        try {
            return (bool) $this->getMetadata($path);
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function read($path): string
    {
        try {
            $response = $this->forceReadFromCDN()
                ? $this->readFromCDN($path)
                : $this->readFromSource($path);

            return (string) $response;

        } catch (ServiceResponseException $e) {
            echo $e.PHP_EOL;
            return '';
        }
    }

    /**
     * @return bool
     */
    protected function forceReadFromCDN()
    {
        return $this->config['cdn']
            && isset($this->config['read_from_cdn'])
            && $this->config['read_from_cdn'];
    }

    /**
     * @param $path
     *
     * @return string
     */
    protected function readFromCDN($path)
    {
        return $this->getHttpClient()
            ->get($this->applyPathPrefix($path))
            ->getBody()
            ->getContents();
    }

    /**
     * @param $path
     *
     * @return string
     */
    protected function readFromSource($path)
    {
        try {
            $response = $this->client->getObject([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $path,
            ]);

            return $response['Body'];
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * @return \GuzzleHttp\Client
     */
    public function getHttpClient()
    {
        return new \GuzzleHttp\Client([
            'timeout'         => $this->config['timeout'],
            'connect_timeout' => $this->config['connect_timeout'],
        ]);
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function readStream($path)
    {
        try {
            $temporaryUrl = $this->getTemporaryUrl($path, Carbon::now()->addMinutes(5));

            $stream = $this->getHttpClient()
                           ->get($temporaryUrl, ['stream' => true])
                           ->getBody()
                           ->detach();

            return ['stream' => $stream];
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * @param string $directory
     * @param bool $recursive
     *
     * @return array|bool
     */
    public function listContents(string $directory = '', bool $recursive = false): iterable
    {
        $list = [];

        $marker = '';
        while (true) {
            $response = $this->listObjects($directory, $recursive, $marker);

            foreach ((array) $response['Contents'] as $content) {
                $list[] = $this->normalizeFileInfo($content);
            }

            if (!$response['IsTruncated']) {
                break;
            }
            $marker = $response['NextMarker'] ?: '';
        }

        return $list;
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function getMetadata($path)
    {
        try {
            return $this->client->headObject([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $path,
            ]);
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function getSize($path)
    {
        $meta = $this->getMetadata($path);

        return isset($meta['ContentLength'])
            ? ['size' => $meta['ContentLength']] : false;
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function getMimetype($path)
    {
        $meta = $this->getMetadata($path);

        return isset($meta['ContentType'])
            ? ['mimetype' => $meta['ContentType']] : false;
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function getTimestamp($path)
    {
        $meta = $this->getMetadata($path);

        return isset($meta['LastModified'])
            ? ['timestamp' => strtotime($meta['LastModified'])] : false;
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function getVisibility($path)
    {
        try {
            $meta = $this->client->getObjectAcl([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $path,
            ]);

            foreach ($meta['Grants'] as $grant) {
                if (isset($grant['Grantee']['URI'])
                    && $grant['Permission'] === 'READ'
                    && strpos($grant['Grantee']['URI'], 'global/AllUsers') !== false
                ) {
                    return ['visibility' => AdapterInterface::VISIBILITY_PUBLIC];
                }
            }

            return ['visibility' => AdapterInterface::VISIBILITY_PRIVATE];
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * @param array $content
     *
     * @return array
     */
    private function normalizeFileInfo(array $content)
    {
        $path = pathinfo($content['Key']);

        return [
            'type'      => substr($content['Key'], -1) === '/' ? 'dir' : 'file',
            'path'      => $content['Key'],
            'timestamp' => Carbon::parse($content['LastModified'])->getTimestamp(),
            'size'      => (int) $content['Size'],
            'dirname'   => $path['dirname'] === '.' ? '' : (string) $path['dirname'],
            'basename'  => (string) $path['basename'],
            'extension' => isset($path['extension']) ? $path['extension'] : '',
            'filename'  => (string) $path['filename'],
        ];
    }

    /**
     * @param string $directory
     * @param bool   $recursive
     * @param string $marker    max return 1000 record, if record greater than 1000
     *                          you should set the next marker to get the full list
     *
     * @return \GuzzleHttp\Command\Result|array
     */
    private function listObjects($directory = '', $recursive = false, $marker = '')
    {
        try {
            return $this->client->listObjects([
                'Bucket'    => $this->getBucketWithAppId(),
                'Prefix'    => ((string) $directory === '') ? '' : ($directory.'/'),
                'Delimiter' => $recursive ? '' : '/',
                'Marker'    => $marker,
                'MaxKeys'   => 1000,
            ]);
        } catch (ServiceResponseException $e) {
            return [
                'Contents'    => [],
                'IsTruncated' => false,
                'NextMarker'  => '',
            ];
        }
    }

    /**
     * @param Config $config
     *
     * @return array
     */
    private function prepareUploadConfig(Config $config)
    {
        $options = [];

        if (isset($this->config['encrypt']) && $this->config['encrypt']) {
            $options['ServerSideEncryption'] = 'AES256';
        }

        if ($config->has('params')) {
            $options = array_merge($options, $config->get('params'));
        }

        if ($config->has('visibility')) {
            $options['ACL'] = $this->normalizeVisibility($config->get('visibility'));
        }

        return $options;
    }

    /**
     * @param $visibility
     *
     * @return string
     */
    private function normalizeVisibility($visibility)
    {
        switch ($visibility) {
            case AdapterInterface::VISIBILITY_PUBLIC:
                $visibility = 'public-read';
                break;
        }

        return $visibility;
    }

    /**
     * @return Client
     */
    public function getCOSClient()
    {
        return $this->client;
    }

    /**
     * @param $method
     * @param $url
     *
     * @return string
     */
    public function getAuthorization($method, $url)
    {
        $cosRequest = new \GuzzleHttp\Psr7\Request($method, $url);

        $signature = new \Qcloud\Cos\Signature(
            $this->config['credentials']['secretId'],
            $this->config['credentials']['secretKey']
        );

        return $signature->createAuthorization($cosRequest);
    }

    public function fileExists(string $path): bool
    {
        return $this->client->doesObjectExist($this->getBucket(), $path);
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function deleteDirectory(string $path): void
    {
        $cos_prefix = $path;
        $nextMarker = '';
        $isTruncated = true;
        while ($isTruncated) {
            try {
                $result = $this->client->listObjects(
                    [
                        'Bucket' => $this->getBucket(), //存储桶名称，由BucketName-Appid 组成，可以在COS控制台查看 https://console.cloud.tencent.com/cos5/bucket
                        'Delimiter' => '',
                        'EncodingType' => 'url',
                        'Marker' => $nextMarker,
                        'Prefix' => $cos_prefix,
                        'MaxKeys' => 1000
                    ]
                );
                $isTruncated = $result['IsTruncated'];
                $nextMarker = $result['NextMarker'];
                foreach ($result['Contents'] as $content) {
                    $cos_file_path = $content['Key'];
                    $local_file_path = $content['Key'];
                    // 按照需求自定义拼接下载路径
                    try {
                        $this->client->deleteObject(array(
                            'Bucket' => $this->config['bucket'], //存储桶名称，由BucketName-Appid 组成，可以在COS控制台查看 https://console.cloud.tencent.com/cos5/bucket
                            'Key' => $cos_file_path,
                        ));
                    } catch (\Exception $e) {
                        echo ($e);
                    }
                }
            } catch (\Exception $e) {
                echo ($e);
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
    }

    public function visibility(string $path): FileAttributes
    {
        // TODO: Implement visibility() method.
        return new FileAttributes($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        // TODO: Implement mimeType() method.
        return new FileAttributes($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        // TODO: Implement lastModified() method.
        return new FileAttributes($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        // TODO: Implement fileSize() method.
        return new FileAttributes($path);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        // TODO: Implement move() method.
    }
}
