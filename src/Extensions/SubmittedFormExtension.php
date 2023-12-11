<?php

namespace Ishannz\SecureUserForms\Extensions;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldPrintButton;
use SilverStripe\ORM\DataExtension;
use SilverStripe\UserForms\Model\Submission\SubmittedFormField;

/**
 * Class SubmittedFormExtension
 */
class SubmittedFormExtension extends DataExtension
{

    /*
     * @inheritDoc
     */
    public function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName('Values');

        $values = GridField::create(
            'Values',
            SubmittedFormField::class,
            $this->getOwner()->Values()->sort('Created', 'ASC')
        );

        $exportColumns = [
            'Title' => 'Title',
            'SecureExportValue' => 'Value', // Make sure exports contain decrypted SecureValue
        ];

        $config = GridFieldConfig::create();
        $config->addComponent(new GridFieldDataColumns());
        $config->addComponent(new GridFieldButtonRow('after'));
        $config->addComponent(new GridFieldExportButton('buttons-after-left', $exportColumns));
        $config->addComponent(new GridFieldPrintButton('buttons-after-left'));
        $values->setConfig($config);

        $fields->addFieldToTab('Root.Main', $values);

        parent::updateCMSFields($fields);
    }

}
