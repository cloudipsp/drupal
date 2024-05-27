# drupalcommerce-2.x

INTRODUCTION
------------
This module provides a Drupal Commerce payment method to embed the payment
services provided by Fondy.

REQUIREMENTS
------------

- Commerce Payment (from [Commerce](http://drupal.org/project/commerce) core)

INSTALLATION
------------

1. Install the Commerce Fondy module by copying the sources to a modules
directory, such as `/modules/contrib` or `sites/[yoursite]/modules`.
2. In your Drupal site, enable the module.

CONFIGURATION
-------------

- Create a new Payment gateway Fondy:</br>
Go to the all payment gateways configuration page:
- Commerce -> Configuration -> Payment -> Payment gateways</br>
+ Add payment gateway;
- Fill the settings:</br>
+ Name: Fondy (or custom)
+ Select plugin: Fondy (redirect to payment page)
+ Display name: Fondy (or custom)
+ Mode: Test or Live
+ Merchant ID: your Merchant ID
+ Secret Key: your Secret key
+ Language: select the language
+ Preauth: enable if need Preauth option
+ Status: Enabled;
- Complete: Save payment;
