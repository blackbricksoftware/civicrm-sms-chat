<?php
declare(strict_types = 1);

use CRM_Smschat_ExtensionUtil as E;

/**
 * The SMS Chat tab body (civicrm/contact/view/smschat).
 *
 * Loaded as an AJAX snippet into the contact-summary tab panel by core's
 * jQuery-UI tab machinery (CRM.loadPage with snippet=json). The page itself
 * is deliberately empty: one <sms-chat> custom element in the template plus
 * the JS/CSS bundle. The Vue app (a custom element built with
 * defineCustomElement) auto-mounts whenever the panel HTML is injected and
 * tears itself down via disconnectedCallback when the tab is closed — no
 * crmLoad choreography.
 *
 * Resource region is the load-bearing subtlety: in snippet mode ONLY the
 * 'ajax-snippet' region is hoisted into the JSON response's
 * scriptUrls/styleUrls (CRM_Core_Page::addAjaxResources), where crm.ajax.js
 * dedupes by src so the bundle executes once per top-level page load.
 * addScriptFile/addStyleFile do NOT auto-pick that region (only
 * addVars/addSetting do), so it is passed explicitly. The bundle must be
 * IIFE — core skips 'esm' resources on this path (TODO in core).
 */
class CRM_Smschat_Page_Chat extends CRM_Core_Page {

  public function run(): void {
    $contactId = CRM_Utils_Request::retrieve('cid', 'Positive', $this, TRUE);

    // Same gate the contact summary itself uses; the API layer re-checks
    // everything this page's data calls do.
    if (!CRM_Contact_BAO_Contact_Permission::allow((int) $contactId, CRM_Core_Permission::VIEW)) {
      CRM_Core_Error::statusBounce(E::ts('You do not have permission to view this contact.'));
    }

    CRM_Utils_System::setTitle(E::ts('SMS Chat'));
    $this->assign('smschatContactId', (int) $contactId);

    $region = CRM_Core_Resources::isAjaxMode() ? 'ajax-snippet' : 'html-header';
    // Bundle shipped in dist/ (built from ui/, committed). Absent until the
    // Vue milestone lands; the file_exists guard keeps the scaffold
    // installable and the tab rendering its placeholder meanwhile.
    if (file_exists(E::path('dist/smschat.js'))) {
      Civi::resources()->addScriptFile(E::LONG_NAME, 'dist/smschat.js', ['region' => $region]);
    }
    if (file_exists(E::path('dist/smschat.css'))) {
      Civi::resources()->addStyleFile(E::LONG_NAME, 'dist/smschat.css', ['region' => $region]);
    }

    parent::run();
  }

}
