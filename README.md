# Silverstripe secure UserForms

## Introduction

This module integrates with [dnadesign/silverstripe-elemental-userforms](https://github.com/dnadesign/silverstripe-elemental-userforms) and 
[silverstripe/silverstripe-userforms](https://github.com/silverstripe/silverstripe-userforms). 
This feature enables secure storage of submitted data in the database. By default, all form data is securely stored 
in the database. However, you can choose to disable this feature in the configuration tab and save the data as plain 
text instead.

## Requirements
* SilverStripe 4.x

## Installation

```sh
composer require ishannz/secure-elemental-userforms
```

Once installed, you need to generate an encryption key that will be used to encrypt all data.

1. Generate a hex key with `vendor/bin/generate-defuse-key` (tool supplied by `defuse/php-encryption`). This will output a ASCII-safe key that starts with `def`.
2. Set this key as the environment variable `ENCRYPT_AT_REST_KEY`.

For development environments you can set this in your `.env` e.g:

```
ENCRYPT_AT_REST_KEY="{generated defuse key}"
```

For more information view SilverStripe [Environment Management](https://docs.silverstripe.org/en/4/getting_started/environment_management/).


## Usage
Refer the following link
https://github.com/madmatt/silverstripe-encrypt-at-rest/blob/master/README.md#usage