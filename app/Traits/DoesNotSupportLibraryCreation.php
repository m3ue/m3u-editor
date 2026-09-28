<?php

namespace App\Traits;

/**
 * Default MediaServer::createLibrary() implementation for media servers that
 * have no library-creation API (everything except Emby). Keeps the "not
 * supported" result shape identical across all of them.
 */
trait DoesNotSupportLibraryCreation
{
    /**
     * @param  list<string>  $paths
     * @return array{success: bool, created: bool, message: string, library: array<string, mixed>|null, drift: bool}
     */
    public function createLibrary(
        string $name,
        string $collectionType,
        array $paths,
        bool $refreshLibrary = true,
        ?string $libraryId = null,
        bool $createIfMissing = true,
    ): array {
        return [
            'success' => false,
            'created' => false,
            'message' => 'Library creation is not supported by this media server.',
            'library' => null,
            'drift' => false,
        ];
    }
}
