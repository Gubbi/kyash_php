# kyash_php
PHP library to access the [Kyash Payment Gateway](http://www.kyash.com/) API.

### Usage
Install the latest version with ```composer require kyash/kyash_php

```php
use Kyash\Collection;

// Get the Kyash Credentials from your Kyash Account API Settings. There is a separate set of credentials for production and development environments.
$kyash = Collection('public_api_id', 'api_secret');

$kyash_code = $kyash->getKyashCode('T12345678');

if ($kyash_code['status'] === 'paid') {
    $kyash->capture($kyash_code['id']);
}
```

Please refer to the Kyash [Merchant API](http://secure.kyash.com/doc/merchant_api.pdf) documentation for more details about the request parameters and response.
All functions in this library take an array of request parameters of their corresponding API call and return an array representing the JSON response of the API.
