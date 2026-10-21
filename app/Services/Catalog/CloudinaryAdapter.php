<?php

namespace App\Services\Catalog;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;

/**
 * A Laravel filesystem disk backed by Cloudinary's HTTP API, so product photos can live there without a vendor SDK.
 *
 * Paths look like `products/12/01H….webp`. Cloudinary names an asset by its public id (the path without the
 * extension) and serves it at `/image/upload/{public id}.{format}`, so the stored path maps straight onto the URL.
 * Only what the image store needs is supported: write, delete, copy, read and URL. Everything is public.
 */
class CloudinaryAdapter implements FilesystemAdapter
{
    /** @param array{cloud_name: string, api_key: string, api_secret: string, url?: string|null} $config */
    public function __construct(private array $config) {}

    public function getUrl(string $path): string
    {
        $base = $this->config['url'] ?? null;
        $base = $base ? rtrim($base, '/') : "https://res.cloudinary.com/{$this->config['cloud_name']}/image/upload";

        return $base.'/'.ltrim($path, '/');
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, 'data:'.$this->mime($path).';base64,'.base64_encode($contents));
    }

    /** @param resource $contents */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, (string) stream_get_contents($contents), $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->upload($destination, $this->getUrl($source));
    }

    public function delete(string $path): void
    {
        $response = $this->signedPost('destroy', ['public_id' => $this->publicId($path), 'invalidate' => 'true']);
        if (! $response->successful() || ! in_array($response->json('result'), ['ok', 'not found'], true)) {
            throw UnableToDeleteFile::atLocation($path, (string) $response->body());
        }
    }

    public function fileExists(string $path): bool
    {
        return Http::timeout(10)->head($this->getUrl($path))->successful();
    }

    public function read(string $path): string
    {
        $response = Http::timeout(20)->get($this->getUrl($path));
        if (! $response->successful()) {
            throw UnableToReadFile::fromLocation($path);
        }

        return $response->body();
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function move(string $source, string $destination, Config $config): void
    {
        throw UnableToMoveFile::fromLocationTo($source, $destination);
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function deleteDirectory(string $path): void {}

    public function createDirectory(string $path, Config $config): void
    {
        throw UnableToCreateDirectory::atLocation($path, 'Cloudinary has no directories to create.');
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Cloudinary assets are always public.');
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'public');
    }

    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes($path, null, null, null, $this->mime($path));
    }

    public function lastModified(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::fileSize($path);
    }

    /** @return iterable<FileAttributes|DirectoryAttributes> */
    public function listContents(string $path, bool $deep): iterable
    {
        return [];
    }

    private function upload(string $path, string $file): void
    {
        $response = $this->signedPost('upload', ['public_id' => $this->publicId($path), 'overwrite' => 'true'], ['file' => $file]);
        if (! $response->successful() || ! $response->json('public_id')) {
            throw UnableToWriteFile::atLocation($path, (string) $response->json('error.message', 'Cloudinary answered '.$response->status()));
        }
    }

    /**
     * Cloudinary signs the request parameters (sorted, minus the file itself) with the API secret.
     *
     * @param  array<string, string>  $params
     * @param  array<string, string>  $extra  sent but not signed (the file)
     */
    private function signedPost(string $action, array $params, array $extra = []): Response
    {
        $params['timestamp'] = (string) time();
        ksort($params);
        $toSign = urldecode(http_build_query($params));
        $signature = sha1($toSign.$this->config['api_secret']);

        $fields = $params + $extra + ['api_key' => $this->config['api_key'], 'signature' => $signature];

        return Http::asMultipart()->timeout(30)->post(
            "https://api.cloudinary.com/v1_1/{$this->config['cloud_name']}/image/{$action}",
            array_map(fn (string $name, string $value) => ['name' => $name, 'contents' => $value], array_keys($fields), array_values($fields)),
        );
    }

    private function publicId(string $path): string
    {
        return preg_replace('/\.[A-Za-z0-9]+$/', '', ltrim($path, '/')) ?? $path;
    }

    private function mime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/webp',
        };
    }
}
