<?php

namespace App\Traits;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @property string|null $relationshipKey Define custom relationship key (if it does not match the table name pattern).
 * @property string|null $pageNamePrefix Define custom child page name prefix (if it does not match the resource's slug).
 */
trait HasParentResource
{
    public Model|int|string|null $parent = null;

    public function bootHasParentResource(): void
    {
        // Retrieve the parent resource's model.
        if ($parent = (request()->route('parent') ?? request()->input('parent'))) {
            $parentResource = $this->getParentResource();

            $this->parent = $parentResource::resolveRecordRouteBinding($parent);

            if (! $this->parent) {
                throw new ModelNotFoundException;
            }
        }
    }

    public static function getParentResource(): string
    {
        $parentResource = static::getResource()::$parentResource;

        if (! isset($parentResource)) {
            throw new Exception('Parent resource is not set for '.static::class);
        }

        return $parentResource;
    }

    protected function applyFiltersToTableQuery(Builder $query): Builder
    {
        // Apply any filters before the parent relationship key is applied.
        $query = parent::applyFiltersToTableQuery($query);

        return $query->where($this->getParentRelationshipKey(), $this->parent->getKey());
    }

    public function getParentRelationshipKey(): string
    {
        // You can set Custom relationship key (if it does not match the table name pattern) via $relationshipKey property.
        // Otherwise, it will be auto-resolved.
        return $this->relationshipKey ?? $this->parent?->getForeignKey();
    }

    public function getChildPageNamePrefix(): string
    {
        return $this->pageNamePrefix ?? (string) str(static::getResource()::getSlug())
            ->replace('/', '.')
            ->afterLast('.');
    }

    public function getBreadcrumbs(): array
    {
        $resource = static::getResource();
        $parentResource = static::getParentResource();

        $breadcrumbs = [
            $parentResource::getUrl() => $parentResource::getBreadCrumb(),
            $parentResource::getUrl(name: 'edit', parameters: ['record' => $this->parent]) => $parentResource::getRecordTitle($this->parent),
            $parentResource::getUrl(name: $this->getChildPageNamePrefix().'.index', parameters: ['parent' => $this->parent]) => $resource::getBreadCrumb(),
        ];

        if (isset($this->record)) {
            $breadcrumbs[] = $resource::getRecordTitle($this->record);
        }

        $breadcrumbs[] = $this->getBreadCrumb();

        return $breadcrumbs;
    }
}
