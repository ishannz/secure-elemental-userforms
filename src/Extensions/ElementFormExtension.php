<?php

namespace Ishannz\SecureUserForms\Extensions;

use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldPrintButton;
use SilverStripe\ORM\DataExtension;

/**
 * Class ElementFormExtension
 */
class ElementFormExtension extends DataExtension
{

    private static $db = [
        'DisableSecureForm' => 'Boolean'
    ];

    /**
     * Override CMS fields so we can remove export and Print button functionality from "all submissions" view.
     * The encryption-at-rest process renders these fields empty in print and export views,
     * and there is too much overhead to rewrite this component to serve up decrypted values.
     * Print and export functionality (with decrypted values) is still available for individual submission records.
     *
     * @inheritDoc
     */
    public function updateCMSFields(FieldList $fields): void
    {
        $grid = $fields->dataFieldByName('Submissions');
        $config = $grid->getConfig()
            ->removeComponentsByType(GridFieldExportButton::class)
            ->removeComponentsByType(GridFieldPrintButton::class);
    }

    /**
     * Perform a write before duplication
     */
    public function onBeforeDuplicate(): void
    {
        $this->owner->write();
    }

    public function updateFormOptions(FieldList $options)
    {
        $options->add(CheckboxField::create('DisableSecureForm','Disable form data encryption'));
    }

}
