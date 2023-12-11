<?php

namespace Ishannz\SecureUserForms\Extensions;

use Madmatt\EncryptAtRest\FieldType\EncryptedText;
use SilverStripe\ORM\DataExtension;

/**
 * Class SubmittedFormFieldExtension
 *
 * Enables encryption of field value for secure storage in db
 *
 */
class SubmittedFormFieldExtension extends DataExtension
{

    /**
     * New fields to add
     */
    private static array $db = [
        'SecureValue' => EncryptedText::class,
    ];

    /**
     * Fields labels to use for summary
     */
    private static array $summary_fields = [
        'Title' => 'Title',
        'FormattedSecureValue' => 'Value',
    ];

    /**
     * Generate a formatted version of SecureValue. Used in reports and email notifications.
     * Converts new lines (which are stored in the database text field) as
     * <brs> so they will output as newlines in the reports.
     */
    public function getFormattedSecureValue(): string
    {
        return $this->getOwner()->dbObject('SecureValue');
    }

    /**
     * Return the SecureValue of this submitted form field suitable for inclusion
     * into the CSV
     */
    public function getSecureExportValue(): string
    {
        return $this->getOwner()->SecureValue;
    }

    /**
     * Before writing object to the db,
     * copy its "Value" in the SecureValue field where it will be encrypted.
     * Then set "Value" to null
     */
    public function onBeforeWrite(): void
    {
        $owner = $this->getOwner();

        if (!$owner->Parent->Parent->DisableSecureForm) {
            $owner->SecureValue = $this->owner->Value;
            $owner->Value = null;
        }

        parent::onBeforeWrite();
    }

}
