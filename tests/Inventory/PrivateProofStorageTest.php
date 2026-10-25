<?php

declare(strict_types=1);

namespace App\Tests\Inventory;

use App\Infrastructure\Inventory\LocalProofQuarantine;
use PHPUnit\Framework\TestCase;

final class PrivateProofStorageTest extends TestCase
{
    public function testMissingPolicyFailsClosedBeforeReadingSource(): void
    {
        $this->expectException(\LogicException::class);
        (new LocalProofQuarantine())->quarantine('00000000-0000-0000-0000-000000000001', '/missing', 'application/pdf');
    }

    public function testRootDirectoryIsRejectedBeforeAnyPermissionsChange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid private storage destination.');
        (new LocalProofQuarantine('/', ['application/pdf'], 100))->quarantine('00000000-0000-0000-0000-000000000001', '/missing', 'application/pdf');
    }

    public function testOverflowingBytePolicyFailsClosed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Private proof storage policy is not configured.');
        (new LocalProofQuarantine(sys_get_temp_dir().'/unused-proof', ['application/pdf'], PHP_INT_MAX))->quarantine('00000000-0000-0000-0000-000000000001', '/missing', 'application/pdf');
    }

    public function testDiscardReportsMissingObjectWithoutFilesystemWarning(): void
    {
        $directory = sys_get_temp_dir().'/quarantine_'.bin2hex(random_bytes(8));
        $source = tempnam(sys_get_temp_dir(), 'proof_');
        self::assertIsString($source);
        file_put_contents($source, "%PDF-1.4\n%%EOF\n");
        $storage = new LocalProofQuarantine($directory, ['application/pdf'], 100);
        $object = $storage->quarantine('00000000-0000-0000-0000-000000000001', $source, 'application/pdf');
        unlink($directory.'/'.$object->objectKey);
        try {
            $storage->discard($object);
            self::fail('Missing object must report failed cleanup.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Cannot clean private proof object.', $failure->getMessage());
        } finally {
            rmdir($directory);
            unlink($source);
        }
    }

    public function testReaderDeniesMissingSymlinkTamperedAndOversizedObjects(): void
    {
        $directory = sys_get_temp_dir().'/quarantine_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $id = '00000000-0000-0000-0000-000000000001';
        $path = $directory.'/'.$id.'.blob';
        $proof = new \App\Application\Inventory\StoredProof($id, $id.'.blob', 4, hash('sha256', 'safe'));
        $reader = new LocalProofQuarantine($directory, ['application/pdf'], 4);
        try {
            foreach (['missing', 'symlink', 'checksum', 'size'] as $scenario) {
                if ('symlink' === $scenario) {
                    symlink('/etc/hostname', $path);
                } elseif ('checksum' === $scenario) {
                    file_put_contents($path, 'evil');
                } elseif ('size' === $scenario) {
                    file_put_contents($path, 'safe but too large');
                }
                try {
                    $reader->read($proof);
                    self::fail('Unavailable or changed bytes must not be disclosed.');
                } catch (\RuntimeException $failure) {
                    self::assertSame(in_array($scenario, ['missing', 'symlink'], true) ? 'Private proof object is unavailable.' : 'Private proof object integrity check failed.', $failure->getMessage());
                }
                if (is_link($path) || is_file($path)) {
                    unlink($path);
                }
            }
            file_put_contents($path, 'safe');
            self::assertSame('safe', $reader->read($proof));
            try {
                $reader->read(new \App\Application\Inventory\StoredProof($id, '../escape', 4, hash('sha256', 'safe')));
                self::fail('Untrusted stored key must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame('safe', file_get_contents($path));
            }
            try {
                (new LocalProofQuarantine())->read($proof);
                self::fail('Missing read policy must fail closed.');
            } catch (\LogicException $failure) {
                self::assertSame('Private proof storage policy is not configured.', $failure->getMessage());
            }
        } finally {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function testUploadRejectsPublicPathsSymlinkDirectoryAndDestinationFile(): void
    {
        $directory = sys_get_temp_dir().'/quarantine_'.bin2hex(random_bytes(8));
        $source = tempnam(sys_get_temp_dir(), 'proof_');
        self::assertIsString($source);
        file_put_contents($source, "%PDF-1.4\n%%EOF\n");
        mkdir($directory, 0700);
        symlink($directory, $directory.'_link');
        try {
            foreach ([dirname(__DIR__, 2).'/public', $directory.'_link', 'relative-directory', $source] as $destination) {
                try {
                    (new LocalProofQuarantine($destination, ['application/pdf'], 100))->quarantine('00000000-0000-0000-0000-000000000001', $source, 'application/pdf');
                    self::fail('Unsafe or unavailable directory must be rejected.');
                } catch (\InvalidArgumentException|\RuntimeException) {
                    self::assertSame([], glob($directory.'/*'));
                }
            }
        } finally {
            unlink($directory.'_link');
            rmdir($directory);
            unlink($source);
        }
    }

    public function testSourceSymlinkCannotBeQuarantinedAndRealSourceRemainsUntouched(): void
    {
        $directory = sys_get_temp_dir().'/quarantine_'.bin2hex(random_bytes(8));
        $source = tempnam(sys_get_temp_dir(), 'proof_');
        self::assertIsString($source);
        $bytes = "%PDF-1.4\n%%EOF\n";
        file_put_contents($source, $bytes);
        symlink($source, $source.'_link');
        try {
            (new LocalProofQuarantine($directory, ['application/pdf'], 100))->quarantine('00000000-0000-0000-0000-000000000001', $source.'_link', 'application/pdf');
            self::fail('A symlink must not be accepted as a trusted temporary upload.');
        } catch (\InvalidArgumentException $failure) {
            self::assertSame('Invalid temporary proof upload.', $failure->getMessage());
            self::assertSame($bytes, file_get_contents($source));
            self::assertSame([], glob($directory.'/*'));
        } finally {
            unlink($source.'_link');
            unlink($source);
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testDirectoryUnderExistingTemporaryFileCannotBeCreated(): void
    {
        $blocker = tempnam(sys_get_temp_dir(), 'proof_blocker_');
        self::assertIsString($blocker);
        file_put_contents($blocker, 'preserve existing file');
        try {
            (new LocalProofQuarantine($blocker.'/private', ['application/pdf'], 100))->quarantine('00000000-0000-0000-0000-000000000001', '/source-must-not-be-read', 'application/pdf');
            self::fail('A regular file cannot become a private storage parent directory.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Cannot create private proof storage.', $failure->getMessage());
            self::assertSame('preserve existing file', file_get_contents($blocker));
            self::assertFalse(is_dir($blocker.'/private'));
        } finally {
            unlink($blocker);
        }
    }

    public function testDiscardRefusesWrongObjectKeyAndRemovedDirectoryWithoutDeletingUnrelatedFile(): void
    {
        $directory = sys_get_temp_dir().'/quarantine_'.bin2hex(random_bytes(8));
        $source = tempnam(sys_get_temp_dir(), 'proof_');
        self::assertIsString($source);
        file_put_contents($source, "%PDF-1.4\n%%EOF\n");
        $storage = new LocalProofQuarantine($directory, ['application/pdf'], 100);
        $object = $storage->quarantine('00000000-0000-0000-0000-000000000001', $source, 'application/pdf');
        file_put_contents($directory.'/unrelated', 'private unrelated bytes');
        try {
            $wrong = new \App\Application\Inventory\QuarantinedProof($object->id, 'unrelated', $object->mimeType, $object->sizeBytes, $object->checksum, $object->storageIdentity);
            try {
                $storage->discard($wrong);
                self::fail('An unrelated object key cannot authorize deletion.');
            } catch (\InvalidArgumentException $failure) {
                self::assertSame('Invalid private proof object.', $failure->getMessage());
                self::assertSame('private unrelated bytes', file_get_contents($directory.'/unrelated'));
                self::assertFileExists($directory.'/'.$object->objectKey);
            }
            rename($directory, $directory.'_preserved');
            try {
                $storage->discard($object);
                self::fail('A removed storage path cannot authorize deletion elsewhere.');
            } catch (\RuntimeException $failure) {
                self::assertSame('Private proof storage location changed.', $failure->getMessage());
                self::assertSame('private unrelated bytes', file_get_contents($directory.'_preserved/unrelated'));
                self::assertFileExists($directory.'_preserved/'.$object->objectKey);
            }
        } finally {
            $remaining = is_dir($directory) ? $directory : $directory.'_preserved';
            unlink($remaining.'/unrelated');
            unlink($remaining.'/'.$object->objectKey);
            rmdir($remaining);
            unlink($source);
        }
    }

    public function testChangedStorageDirectoryCannotDeleteAnotherObject(): void
    {
        $directory = sys_get_temp_dir().'/quarantine_'.bin2hex(random_bytes(8));
        $source = tempnam(sys_get_temp_dir(), 'proof_');
        self::assertIsString($source);
        file_put_contents($source, "%PDF-1.4\n%%EOF\n");
        $storage = new LocalProofQuarantine($directory, ['application/pdf'], 100);
        $object = $storage->quarantine('00000000-0000-0000-0000-000000000001', $source, 'application/pdf');
        rename($directory, $directory.'_original');
        mkdir($directory, 0700);
        file_put_contents($directory.'/'.$object->objectKey, 'unrelated object');
        try {
            $storage->discard($object);
            self::fail('Replacement directory must not authorize deletion.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Private proof storage location changed.', $failure->getMessage());
            self::assertSame('unrelated object', file_get_contents($directory.'/'.$object->objectKey));
            self::assertFileExists($directory.'_original/'.$object->objectKey);
        } finally {
            unlink($directory.'/'.$object->objectKey);
            rmdir($directory);
            unlink($directory.'_original/'.$object->objectKey);
            rmdir($directory.'_original');
            unlink($source);
        }
    }

    public function testRealContentInspectionLimitsCollisionAndPrivatePermissions(): void
    {
        $directory = sys_get_temp_dir().'/quarantine_'.bin2hex(random_bytes(8));
        $source = tempnam(sys_get_temp_dir(), 'proof_');
        self::assertIsString($source);
        mkdir($directory, 0777);
        chmod($directory, 0777);
        $storage = new LocalProofQuarantine($directory, ['application/pdf'], 100);
        try {
            file_put_contents($source, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
            $object = $storage->quarantine('00000000-0000-0000-0000-000000000001', $source, 'application/pdf');
            self::assertSame(0700, fileperms($directory) & 0777);
            self::assertSame(0600, fileperms($directory.'/'.$object->objectKey) & 0777);
            try {
                $storage->quarantine($object->id, $source, 'application/pdf');
                self::fail('An existing object must not be replaced.');
            } catch (\RuntimeException) {
                self::assertSame(35, filesize($directory.'/'.$object->objectKey));
            }
            $storage->discard($object);
            self::assertSame([], glob($directory.'/*'));
            foreach ([['text/plain', 'hello'], ['application/pdf', 'hello'], ['application/pdf', str_repeat('x', 101)], ['application/pdf', '']] as [$mime, $bytes]) {
                file_put_contents($source, $bytes);
                try {
                    $storage->quarantine($object->id, $source, $mime);
                    self::fail('Invalid content must be rejected.');
                } catch (\InvalidArgumentException) {
                    self::assertSame([], glob($directory.'/*'));
                }
            }
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
            unlink($source);
        }
    }
}
