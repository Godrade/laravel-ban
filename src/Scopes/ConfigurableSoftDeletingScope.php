<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class ConfigurableSoftDeletingScope extends SoftDeletingScope
{
    /** @param Builder<*> $builder */
    public function apply(Builder $builder, Model $model)
    {
        if (config('ban.soft_delete', true)) {
            $builder->whereNull($builder->qualifyColumn($this->getDeletedAtColumn($builder)));
        }
    }

    public function extend(Builder $builder)
    {
        parent::extend($builder);

        $builder->onDelete(function (Builder $builder) {
            if (! config('ban.soft_delete', true)) {
                return $builder->toBase()->delete();
            }

            return $builder->update([
                $this->getDeletedAtColumn($builder) => $builder->getModel()->freshTimestampString(),
            ]);
        });
    }

    protected function addRestore(Builder $builder)
    {
        $scope = $this;
        $builder->macro('restore', function (Builder $builder) use ($scope) {
            if (! config('ban.soft_delete', true)) {
                return 0;
            }

            return $builder->withoutGlobalScope($scope)->update([$scope->getDeletedAtColumn($builder) => null]);
        });
    }

    protected function addWithoutTrashed(Builder $builder)
    {
        $scope = $this;
        $builder->macro('withoutTrashed', function (Builder $builder) use ($scope) {
            $builder->withoutGlobalScope($scope);

            if (config('ban.soft_delete', true)) {
                $builder->whereNull($builder->getModel()->qualifyColumn($scope->getDeletedAtColumn($builder)));
            }

            return $builder;
        });
    }

    protected function addOnlyTrashed(Builder $builder)
    {
        $scope = $this;
        $builder->macro('onlyTrashed', function (Builder $builder) use ($scope) {
            $builder->withoutGlobalScope($scope);

            return config('ban.soft_delete', true)
                ? $builder->whereNotNull($builder->getModel()->qualifyColumn($scope->getDeletedAtColumn($builder)))
                : $builder->whereRaw('1 = 0');
        });
    }
}
