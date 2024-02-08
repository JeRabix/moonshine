<?php

declare(strict_types=1);

namespace MoonShine\Http\Controllers;

use Illuminate\Support\Arr;
use Illuminate\Contracts\Database\Eloquent\Builder;
use MoonShine\Contracts\Fields\Relationships\HasAsyncSearch;
use MoonShine\Fields\Relationships\MorphTo;
use MoonShine\Http\Requests\Relations\RelationModelFieldRequest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RelationModelFieldController extends MoonShineController
{
    /**
     * @throws Throwable
     */
    public function search(RelationModelFieldRequest $request): Response
    {
        $field = $request->getPageField();

        if (! $field instanceof HasAsyncSearch) {
            return response()->json();
        }

        /* @var \MoonShine\Resources\ModelResource $resource */
        $resource = $field->getResource();

        $model = $resource->getModel();

        $searchColumn = $field->asyncSearchColumn() ?? $resource->column();

        if ($field instanceof MorphTo) {
            $field->resolveFill([], $model);

            if ($request->has('_parent_field')) {
                $json = $request->get('_parent_field', []);

                $item = Arr::first($json, fn($item): bool => Arr::get($item, $field->getMorphType()) !== null);

                $morphClass = Arr::get($item, $field->getMorphType());
            } else {
                $morphClass = $field->getWrapName()
                    ? data_get($request->get($field->getWrapName(), []), $field->getMorphType())
                    : $request->get($field->getMorphType());
            }

            $model = new $morphClass();
            $searchColumn = $field->getSearchColumn($morphClass);
        }

        $query = $model->newModelQuery();

        if (is_closure($field->asyncSearchQuery())) {
            $query = value(
                $field->asyncSearchQuery(),
                $query,
                $request,
                $field
            );
        }

        $term = $request->get('query');
        $values = $request->get($field->column(), '') ?? '';

        $except = is_array($values)
            ? array_keys($values)
            : array_filter(explode(',', (string) $values));

        $offset = $request->get('offset', 0);

        $query->when(
            $term,
            fn (Builder $q) => $q->where(
                $searchColumn,
                'LIKE',
                "%$term%"
            )
        )
            ->whereNotIn($model->getKeyName(), $except)
            ->offset($offset)
            ->limit($field->asyncSearchCount());

        return response()->json(
            $query->get()->map(
                fn ($model): array => $field->asyncResponseData($model, $searchColumn)
            )->toArray()
        );
    }

    public function searchRelations(RelationModelFieldRequest $request): mixed
    {
        /* @var \MoonShine\Resources\ModelResource $parentResource */
        $parentResource = $request->getResource();

        $parentItem = $parentResource->getItemOrInstance();

        $field = $request->getField();

        $field?->resolveFill(
            $parentItem->toArray(),
            $parentItem
        );

        return $field?->value();
    }
}
