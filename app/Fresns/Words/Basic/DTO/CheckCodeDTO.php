<?php

/*
 * Fresns (https://fresns.org)
 * Copyright (C) 2021-Present Jarvis Tang
 * Released under the Apache-2.0 License.
 */

namespace App\Fresns\Words\Basic\DTO;

use Fresns\DTO\DTO;

/**
 * Class CheckCodeDTO.
 */
class CheckCodeDTO extends DTO
{
    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            'type' => ['integer', 'required', 'in:1,2'],
            'account' => ['string', 'required'],
            'countryCode' => ['integer', 'nullable', 'required_if:type,2'],
            'verifyCode' => ['string', 'required'],
        ];
    }
}
