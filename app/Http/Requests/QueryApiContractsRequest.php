<?php

namespace App\Http\Requests;

use App\Support\ApiContracts\ContractRepository;
use Closure;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

#[FailOnUnknownFields]
class QueryApiContractsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['sometimes', 'string', Rule::in(['discovery', 'search', 'operation', 'schema', 'changes', 'download'])],
            'q' => ['sometimes', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'operationId' => ['required_if:action,operation', 'string', 'max:255', 'regex:/^[a-zA-Z][a-zA-Z0-9._-]+$/'],
            'schemaName' => ['required_if:action,schema', 'string', 'max:255'],
            'revision' => ['required_if:action,changes', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'artifact' => ['required_if:action,download', 'string', Rule::in(ContractRepository::ARTIFACTS)],
            'sha256' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'max_bytes' => ['sometimes', 'integer', 'min:1024', 'max:'.config('api-contracts.http.max_bytes')],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Unknown documentation query parameter.');
            }
        }];
    }
}
