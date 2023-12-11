<?php

namespace Ishannz\SecureUserForms\Extensions;

use Ishannz\SecureUserForms\Controllers\SecureUserDefinedFormController;
use SilverStripe\ORM\DataExtension;

class ElementFormControllerExtension extends DataExtension
{

    /**
     * Allow us to set our own controller
     */
    public function onBeforeInit(): void
    {
        $owner = $this->getOwner();
        $owner->setUserFormController(SecureUserDefinedFormController::create($owner->element));
    }

}
