# Yellow SDK PHP

## Installation :
Get more information  with the
[Documentation](http://yellowpay.co/docs/api/).

### Installing via Composer

The recommended way to install Yellow PHP SDK is through
[Composer](http://getcomposer.org).

```bash
# Install Composer
curl -sS https://getcomposer.org/installer | php
```

Next, run the Composer command to install the latest master version of Yellow PHP SDK:

```bash
php composer.phar require yellow/php-sdk
```

After installing, you need to require Composer's autoloader:

```php
require 'vendor/autoload.php';
```
## Example :
```php
<?php
use Yellow\Bitcoin\Invoice;
$api_key = "Your API public key";
$api_secret = "Your API Private key";
$yellow = new Invoice($api_key,$api_secret);
/// this will return an array with invoice data
$paylaod = array(
    "base_price" => 10,
    "base_ccy"   => "USD",
    "callback"   => "http://yourstore.local/checkout/status/"
);
$invoice  = $yellow->createInvoice($payload);
var_dump($invoice);
/// this will return an array with invoice status data
$status = $yellow->checkInvoiceStatus($invoice["id"]);
var_dump($status);
```

### IPN validation :
 to validate the IPN simply use following snippet on your IPN page/controller 
```php
 <?php
 use Yellow\Bitcoin\Invoice;
 $api_key = "Your API public key";
 $api_secret = "Your API Private key";
 $yellow = new Invoice($api_key,$api_secret);
 $isValidIPN = $yellow->verifyIPN(); //bool
 var_dump($isValidIPN);
```

### Documentation

More information can be found in the online documentation at
http://yellowpay.co/docs/api/.
