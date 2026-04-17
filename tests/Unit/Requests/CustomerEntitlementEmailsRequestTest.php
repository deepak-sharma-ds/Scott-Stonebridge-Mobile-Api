<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\CustomerEntitlementEmailsRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CustomerEntitlementEmailsRequestTest extends TestCase
{
    public function test_it_parses_multiple_unique_emails_from_textarea_input()
    {
        $request = CustomerEntitlementEmailsRequest::create('/', 'POST', [
            'emails' => "first@example.com\nSECOND@example.com, first@example.com ; third@example.com",
        ]);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
        $this->assertSame([
            'first@example.com',
            'second@example.com',
            'third@example.com',
        ], $request->emails());
    }

    public function test_it_rejects_invalid_email_entries()
    {
        $request = CustomerEntitlementEmailsRequest::create('/', 'POST', [
            'emails' => "valid@example.com\nnot-an-email",
        ]);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('emails', $validator->errors()->toArray());
    }
}
