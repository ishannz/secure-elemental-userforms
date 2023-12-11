<?php

namespace Ishannz\SecureUserForms\Controllers;

use Psr\Log\LoggerInterface;
use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Upload;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\ValidationException;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Security;
use SilverStripe\UserForms\Control\UserDefinedFormController;
use SilverStripe\UserForms\Extension\UserFormFileExtension;
use SilverStripe\UserForms\Model\EditableFormField\EditableFileField;
use SilverStripe\UserForms\Model\Recipient\EmailRecipient;
use SilverStripe\UserForms\Model\Submission\SubmittedFileField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\View\ArrayData;
use SilverStripe\View\SSViewer;
use Swift_RfcComplianceException;

class SecureUserDefinedFormController extends UserDefinedFormController
{

    /**
     * We are overriding this method for the sole purpose of setting the display field
     * for PlainText emails to use "SecureValue" (rather than "Value")
     * {@see SubmittedFormFieldExtension} for details
     *
     * Changes made by Silverstripe are commented with "Silverstripe edited-start", "Silverstripe edited-end"
     * for blocks of code and "Silverstripe next-line edited" or "Silverstripe current-line edited" for lines of code.
     *
     * @inheritDoc
     * @throws ValidationException
     */
    public function process($data, $form)
    {
        $submittedForm = SubmittedForm::create();
        $submittedForm->SubmittedByID = Security::getCurrentUser() ? Security::getCurrentUser()->ID : 0;
        $submittedForm->ParentClass = get_class($this->data());
        $submittedForm->ParentID = $this->ID;

        // if saving is not disabled save now to generate the ID
        if (!$this->DisableSaveSubmissions) {
            $submittedForm->write();
        }

        $attachments = [];
        $submittedFields = ArrayList::create();

        foreach ($this->data()->Fields() as $field) {
            if (!$field->showInReports()) {
                continue;
            }

            $submittedField = $field->getSubmittedFormField();
            $submittedField->ParentID = $submittedForm->ID;
            $submittedField->Name = $field->Name;
            $submittedField->Title = $field->getField('Title');

            // save the value from the data
            if ($field->hasMethod('getValueFromData')) {
                $submittedField->Value = $field->getValueFromData($data);
            } else {
                if (isset($data[$field->Name])) {
                    $submittedField->Value = $data[$field->Name];
                }
            }

            if (!empty($data[$field->Name])) {
                if (in_array(EditableFileField::class, $field->getClassAncestry())) {
                    if (!empty($_FILES[$field->Name]['name'])) {
                        $foldername = $field->getFormField()->getFolderName();

                        // create the file from post data
                        $upload = Upload::create();

                        try {
                            $upload->loadIntoFile($_FILES[$field->Name], null, $foldername);
                        } catch (ValidationException $e) {
                            $validationResult = $e->getResult();

                            foreach ($validationResult->getMessages() as $message) {
                                $form->sessionMessage($message['message'], ValidationResult::TYPE_ERROR);
                            }

                            return Controller::curr()->redirectBack();
                        }

                        /** @var AssetContainer|File $file */
                        $file = $upload->getFile();
                        $file->ShowInSearch = 0;
                        $file->UserFormUpload = UserFormFileExtension::USER_FORM_UPLOAD_TRUE;
                        $file->write();

                        // generate image thumbnail to show in asset-admin
                        // you can run userforms without asset-admin, so need to ensure asset-admin is installed
                        if (class_exists(AssetAdmin::class)) {
                            AssetAdmin::singleton()->generateThumbnails($file);
                        }

                        // write file to form field
                        $submittedField->UploadedFileID = $file->ID;

                        // attach a file only if lower than 1MB
                        if ($file->getAbsoluteSize() < 1024 * 1024 * 1) {
                            $attachments[] = $file;
                        }
                    }
                }
            }

            $submittedField->extend('onPopulationFromField', $field);

            if (!$this->DisableSaveSubmissions) {
                $submittedField->write();
            }

            $submittedFields->push($submittedField);
        }

        $emailData = [
            'Sender' => Security::getCurrentUser(),
            'HideFormData' => false,
            'Fields' => $submittedFields,
            'Body' => '',
        ];

        $this->extend('updateEmailData', $emailData, $attachments);

        // email users on submit.
        $recipients = $this->FilteredEmailRecipients($data, $form);

        if ($recipients) {
            foreach ($recipients as $recipient) {
                $email = Email::create()
                    ->setHTMLTemplate('email/SubmittedFormEmail')
                    ->setPlainTemplate('email/SubmittedFormEmailPlain');

                // Merge fields are used for CMS authors to reference specific form fields in email content
                $mergeFields = $this->getMergeFieldsMap($emailData['Fields']);

                if ($attachments) {
                    foreach ($attachments as $file) {
                        /** @var File $file */
                        if ((int) $file->ID === 0) {
                            continue;
                        }

                        $email->addAttachmentFromData(
                            $file->getString(),
                            $file->getFilename(),
                            $file->getMimeType()
                        );
                    }
                }

                if (!$recipient->SendPlain && $recipient->emailTemplateExists()) {
                    $email->setHTMLTemplate($recipient->EmailTemplate);
                }

                // Add specific template data for the current recipient
                $emailData['HideFormData'] = (bool) $recipient->HideFormData;
                // Include any parsed merge field references from the CMS editor - this is already escaped
                // This string substitution works for both HTML and plain text emails.
                // $recipient->getEmailBodyContent() will retrieve the relevant version of the email
                $emailData['Body'] = SSViewer::execute_string($recipient->getEmailBodyContent(), $mergeFields);

                // Push the template data to the Email's data
                foreach ($emailData as $key => $value) {
                    $email->addData($key, $value);
                }

                // check to see if they are a dynamic reply to. eg based on a email field a user selected
                $emailFrom = $recipient->SendEmailFromField();

                if ($emailFrom && $emailFrom->exists()) {
                    $submittedFormField = $submittedFields->find('Name', $recipient->SendEmailFromField()->Name);

                    /** Silverstripe next-line edited */
                    if ($submittedFormField && is_string($submittedFormField->SecureValue)) {
                        /** Silverstripe next-line edited */
                        $email->setReplyTo(explode(',', $submittedFormField->SecureValue));
                    }
                } elseif ($recipient->EmailReplyTo) {
                    $email->setReplyTo(explode(',', $recipient->EmailReplyTo));
                }

                // check for a specified from; otherwise fall back to server defaults
                if ($recipient->EmailFrom) {
                    $email->setFrom(explode(',', $recipient->EmailFrom));
                }

                // check to see if they are a dynamic reciever eg based on a dropdown field a user selected
                $emailTo = $recipient->SendEmailToField();

                try {
                    if ($emailTo && $emailTo->exists()) {
                        $submittedFormField = $submittedFields->find('Name', $recipient->SendEmailToField()->Name);

                        /** Silverstripe next-line edited */
                        if ($submittedFormField && is_string($submittedFormField->SecureValue)) {
                            /** Silverstripe next-line edited */
                            $email->setTo(explode(',', $submittedFormField->SecureValue));
                        } else {
                            $email->setTo(explode(',', $recipient->EmailAddress));
                        }
                    } else {
                        $email->setTo(explode(',', $recipient->EmailAddress));
                    }
                } catch (Swift_RfcComplianceException $e) {
                    // The sending address is empty and/or invalid. Log and skip sending.
                    $error = sprintf(
                        'Failed to set sender for userform submission %s: %s',
                        $submittedForm->ID,
                        $e->getMessage()
                    );

                    Injector::inst()->get(LoggerInterface::class)->notice($error);

                    continue;
                }

                // Set the 'Subject' of the email
                $this->setSubject($email, $recipient, $submittedFields, $mergeFields);

                $this->extend('updateEmail', $email, $recipient, $emailData);

                if ((bool)$recipient->SendPlain) {
                    // decode previously encoded html tags because the email is being sent as text/plain
                    $body = html_entity_decode($emailData['Body']) . "\n";

                    if (isset($emailData['Fields']) && !$emailData['HideFormData']) {
                        foreach ($emailData['Fields'] as $field) {
                            if ($field instanceof SubmittedFileField) {
                                // Silverstripe next-line edited
                                $body .= $field->Title . ': ' . $field->SecureExportValue ." \n";
                            } else {
                                // Silverstripe next-line edited
                                $fieldValue = $field->SecureValue;

                                // Use the 'Value' of the field instead of 'SecureValue' so we extract the correct
                                // data. It is important to note that we are not sending encrypted data,
                                // we are decrypting it before it is sent then it is re-encrypted
                                // through TLS by the mail service.
                                if ($this->DisableSaveSubmissions) {
                                    $fieldValue = $field->Value;
                                }

                                $body .= $field->Title . ': ' . $fieldValue . " \n";
                            }
                        }
                    }

                    $email->setBody($body);

                    // Sends a 'Plain Text' format email
                    $email->sendPlain();
                } else {
                    // Ensure we set the 'SecureData' property for each of the fields
                    // so that the HTML email templates render as expected.
                    if ($this->DisableSaveSubmissions) {
                        $this->setSecureData($email);
                    }

                    // Sends a 'HTML' format email
                    $email->send();
                }
            }
        }

        $submittedForm->extend('updateAfterProcess');

        $session = $this->getRequest()->getSession();
        $session->clear(sprintf('FormInfo.{%s}.errors', $form->FormName()));
        $session->clear(sprintf('FormInfo.{%s}.data', $form->FormName()));

        $referrer = isset($data['Referrer']) ? '?referrer=' . urlencode($data['Referrer']) : '';

        // set a session variable from the security ID to stop people accessing
        // the finished method directly.
        if (!$this->DisableAuthenicatedFinishAction) {
            if (isset($data['SecurityID'])) {
                $session->set('FormProcessed', $data['SecurityID']);
            } else {
                // if the form has had tokens disabled we still need to set FormProcessed
                // to allow us to get through the finshed method
                if (!$this->Form()->getSecurityToken()->isEnabled()) {
                    $randNum = rand(1, 1000);
                    $randHash = md5($randNum);
                    $session->set('FormProcessed', $randHash);
                    $session->set('FormProcessedNum', $randNum);
                }
            }
        }

        if (!$this->DisableSaveSubmissions) {
            $session->set('userformssubmission'. $this->ID, $submittedForm->ID);
        }

        return $this->redirect($this->Link('finished') . $referrer . $this->config()->get('finished_anchor'));
    }

    /**
     * Takes the dataset from the form submission and performs a direct copy
     * of 'Value' to 'SecureValue' on each of its fields. This allows us to
     * render the correct set of data in HTML email templates.
     */
    public function setSecureData(Email $email): void
    {
        // Get existing set of email data
        $data = $email->getData();

        if (!isset($data['Fields']) && $data['HideFormData']) {
            return;
        }

        // Setup fields data
        $secureFieldsData = new ArrayList();
        $fieldData = $data['Fields'];

        // Perform a direct copy of 'Value' into 'SecureValue' on each of its fields
        foreach ($fieldData as $field) {
            $field->SecureValue = $field->Value;
            $secureFieldsData->push($field);
        }

        // Reconstruct dataset with new 'SecureValue' data
        $secureData = [
            'Sender' => isset($data['Sender']) ?? null,
            'HideFromData' => isset($data['HideFormData']) ?? 0,
            'Fields' => $secureFieldsData,
            'Body' => $data['Body'] !== '' ?? null, // Sets the 'Body' if the output of data is not an empty string
        ];

        $email->setData($secureData);
    }

    /**
     * Sets the 'subject' of the email regardless if it's manually set for an email recipient in the CMS or if it's
     * dynamically set by a user from an elected FormField.
     *
     * Uses the 'Value' instead of the 'SecureValue' when the 'DisableSaveSubmissions' option is enabled since the
     * submission record is not stored in the database.
     */
    public function setSubject(
        Email $email,
        EmailRecipient $recipient,
        ArrayList $submittedFields,
        ArrayData $mergeFields
    ): void {
        // Get the email subject field for this EmailRecipient
        $emailSubject = $recipient->SendEmailSubjectField();

        // Search for the associated submitted field based on the 'Name' of the email subject field
        $submittedFormField = $submittedFields->find('Name', $recipient->SendEmailSubjectField()->Name);

        // If the email subject or submitted field does not exist then we use the default values and early exit
        if ((!$emailSubject || !$emailSubject->exists()) || !$submittedFormField) {
            $email->setSubject(SSViewer::execute_string($recipient->EmailSubject, $mergeFields));

            return;
        }

        // is the form encrypted?
        if ($submittedFormField->SecureValue) {
            $email->setSubject($submittedFormField->SecureValue);

            return;
        }

        // is saving the submissions disabled?
        if ($this->DisableSaveSubmissions && $submittedFormField->Value) {
            $email->setSubject($submittedFormField->Value);

            return;
        }

        // otherwise use the default values
        $email->setSubject(SSViewer::execute_string($recipient->EmailSubject, $mergeFields));
    }

}
