<?php

namespace VentureDrake\LaravelCrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'organization_name' => 'required|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'vat_number' => 'nullable|string|max:32',
            'fiscal_address' => 'nullable|string|max:2000',
            'business_email' => 'nullable|email|max:255',
            'business_phone' => 'nullable|string|max:64',
            'website_url' => 'nullable|url|max:255',
            'country' => 'required',
            'language' => 'required',
            'currency' => 'required',
            'timezone' => 'required|timezone',
            'date_format' => 'required',
            'time_format' => 'required',
        ];
    }
}
