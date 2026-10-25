<?php

declare(strict_types=1);

namespace App\Infrastructure\Inventory;

use App\Application\Inventory\PrivateProofReader;
use App\Application\Inventory\PrivateProofStorage;
use App\Application\Inventory\QuarantinedProof;
use App\Application\Inventory\StoredProof;

final readonly class LocalProofQuarantine implements PrivateProofStorage, PrivateProofReader
{
    /** @param list<string>|null $allowedMimeTypes */
    public function __construct(private ?string $directory = null, private ?array $allowedMimeTypes = null, private ?int $maximumBytes = null)
    {
    }

    public function quarantine(string $objectId, string $temporaryPath, string $declaredMimeType): QuarantinedProof
    {
        if (null === $this->directory || null === $this->allowedMimeTypes || null === $this->maximumBytes || $this->maximumBytes < 1 || PHP_INT_MAX === $this->maximumBytes || [] === $this->allowedMimeTypes) {
            throw new \LogicException('Private proof storage policy is not configured.');
        }
        if ('' === trim($this->directory, '/') || !str_starts_with($this->directory, '/') || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $objectId)) {
            throw new \InvalidArgumentException('Invalid private storage destination.');
        }
        $public = dirname(__DIR__, 3).'/public';
        if (str_contains($this->directory, '..') || $this->directory === $public || str_starts_with($this->directory, $public.'/')) {
            throw new \InvalidArgumentException('Proof storage must be outside the public directory.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true)) {
            throw new \RuntimeException('Cannot create private proof storage.');
        }
        $directory = realpath($this->directory);
        if (false === $directory || $directory !== rtrim($this->directory, '/') || $directory === $public || str_starts_with($directory, $public.'/')) {
            throw new \InvalidArgumentException('Proof storage must be outside the public directory.');
        }
        if (!chmod($directory, 0700)) {
            throw new \RuntimeException('Cannot secure private proof directory.');
        }
        $identity = $this->directoryIdentity($directory);
        if (!is_file($temporaryPath) || is_link($temporaryPath)) {
            throw new \InvalidArgumentException('Invalid temporary proof upload.');
        }
        $bytes = file_get_contents($temporaryPath, false, null, 0, $this->maximumBytes + 1);
        if (false === $bytes || '' === $bytes || strlen($bytes) > $this->maximumBytes) {
            throw new \InvalidArgumentException('Invalid proof upload size.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!is_string($mime) || $mime !== $declaredMimeType || !in_array($mime, $this->allowedMimeTypes, true)) {
            throw new \InvalidArgumentException('Proof content type is not permitted.');
        }
        $key = $objectId.'.blob';
        $path = $directory.'/'.$key;
        $handle = @fopen($path, 'x+b');
        if (false === $handle) {
            throw new \RuntimeException('Cannot create private proof object.');
        }
        try {
            if (!chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('Cannot write private proof object.');
            }
        } catch (\Throwable $failure) {
            fclose($handle);
            if (!unlink($path)) {
                throw new \RuntimeException('Cannot clean failed private proof object.', 0, $failure);
            }
            throw $failure;
        }
        fclose($handle);

        return new QuarantinedProof($objectId, $key, $mime, strlen($bytes), hash('sha256', $bytes), $identity);
    }

    public function read(StoredProof $proof): string
    {
        if (null === $this->directory || null === $this->maximumBytes || $this->maximumBytes < 1 || PHP_INT_MAX === $this->maximumBytes) {
            throw new \LogicException('Private proof storage policy is not configured.');
        }
        $directory = realpath($this->directory);
        $public = dirname(__DIR__, 3).'/public';
        if (false === $directory || '' === trim($directory, '/') || $directory !== rtrim($this->directory, '/') || $directory === $public || str_starts_with($directory, $public.'/') || $proof->objectKey !== $proof->id.'.blob' || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\.blob$/D', $proof->objectKey)) {
            throw new \InvalidArgumentException('Invalid private proof location.');
        }
        $path = $directory.'/'.$proof->objectKey;
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('Private proof object is unavailable.');
        }
        $bytes = @file_get_contents($path, false, null, 0, $this->maximumBytes + 1);
        if (false === $bytes || strlen($bytes) > $this->maximumBytes || strlen($bytes) !== $proof->sizeBytes || !hash_equals($proof->checksum, hash('sha256', $bytes))) {
            throw new \RuntimeException('Private proof object integrity check failed.');
        }

        return $bytes;
    }

    private function directoryIdentity(string $directory): string
    {
        clearstatcache(true, $directory);
        $metadata = stat($directory);
        if (false === $metadata) {
            throw new \RuntimeException('Cannot inspect private storage directory.');
        }

        return $metadata['dev'].':'.$metadata['ino'];
    }

    public function discard(QuarantinedProof $proof): void
    {
        if (null === $this->directory || $proof->objectKey !== $proof->id.'.blob' || !preg_match('/^[a-f0-9-]{36}\.blob$/D', $proof->objectKey)) {
            throw new \InvalidArgumentException('Invalid private proof object.');
        }
        $directory = realpath($this->directory);
        if (false === $directory || $directory !== rtrim($this->directory, '/') || $this->directoryIdentity($directory) !== $proof->storageIdentity || is_link($directory.'/'.$proof->objectKey)) {
            throw new \RuntimeException('Private proof storage location changed.');
        }
        if (!@unlink($directory.'/'.$proof->objectKey)) {
            throw new \RuntimeException('Cannot clean private proof object.');
        }
    }
}
