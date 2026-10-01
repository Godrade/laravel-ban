<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Traits;

use Godrade\LaravelBan\Scopes\ConfigurableSoftDeletingScope;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Keep Laravel's soft-delete API available even on schemas without deleted_at. */
trait HasConfigurableSoftDeletes
{
    use SoftDeletes {
        initializeSoftDeletes as private initializeLaravelSoftDeletes;
        performDeleteOnModel as private performSoftDeleteOnModel;
        restore as private restoreSoftDeletedModel;
        trashed as private isSoftDeleted;
    }

    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new ConfigurableSoftDeletingScope);
    }

    public function initializeSoftDeletes(): void
    {
        if (config('ban.soft_delete', true)) {
            $this->initializeLaravelSoftDeletes();
        }
    }

    protected function performDeleteOnModel(): void
    {
        if (config('ban.soft_delete', true)) {
            $this->performSoftDeleteOnModel();

            return;
        }

        $this->setKeysForSaveQuery($this->newModelQuery())->forceDelete();
        $this->exists = false;
    }

    public function restore()
    {
        return config('ban.soft_delete', true) ? $this->restoreSoftDeletedModel() : false;
    }

    public function trashed(): bool
    {
        return config('ban.soft_delete', true) && $this->isSoftDeleted();
    }
}
