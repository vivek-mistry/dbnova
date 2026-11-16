<?php

namespace App\Http\Requests\Tables;

use App\Database\DTOs\Filter;
use App\Database\DTOs\FilterOperator;
use App\Database\DTOs\Page;
use App\Database\DTOs\PageRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Paging, sorting and filtering for a table browse request. Column names are
 * checked against the table by the controller.
 */
class TableRowsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.PageRequest::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:4096'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'sort' => ['nullable', 'string', 'max:64'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'filters' => ['nullable', 'array', 'max:20'],
            'filters.*.column' => ['required', 'string', 'max:64'],
            'filters.*.operator' => ['required', Rule::enum(FilterOperator::class)],
            'filters.*.value' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('cursor') && Page::decodeCursor($this->string('cursor')->toString()) === null) {
                    $validator->errors()->add('cursor', 'The page cursor is invalid.');
                }
            },
        ];
    }

    public function toPageRequest(): PageRequest
    {
        return new PageRequest(
            limit: $this->integer('limit', config()->integer('dbnova.limits.page_size')),
            sort: $this->filled('sort') ? $this->string('sort')->toString() : null,
            direction: $this->input('direction') === 'desc' ? 'desc' : 'asc',
            filters: $this->filters(),
            cursor: $this->filled('cursor') ? Page::decodeCursor($this->string('cursor')->toString()) : null,
            offset: $this->integer('offset'),
        );
    }

    /**
     * @return list<Filter>
     */
    public function filters(): array
    {
        /** @var list<array{column: string, operator: string, value?: string|null}> $filters */
        $filters = $this->validated('filters') ?? [];

        return array_map(fn (array $filter): Filter => new Filter(
            column: $filter['column'],
            operator: FilterOperator::from($filter['operator']),
            value: $filter['value'] ?? '',
        ), $filters);
    }

    /**
     * Column names the request refers to, for checking against the table.
     *
     * @return list<string>
     */
    public function referencedColumns(): array
    {
        $columns = array_map(fn (Filter $filter): string => $filter->column, $this->filters());

        if ($this->filled('sort')) {
            $columns[] = $this->string('sort')->toString();
        }

        return $columns;
    }
}
