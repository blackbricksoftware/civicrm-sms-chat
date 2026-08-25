{* SMS Chat Settings Form *}
<div class="crm-block crm-form-block crm-smschat-settings-form-block">

  {if $smschatOverridden and $smschatOverridden|@count}
    <div class="messages status no-popup">
      <p><strong>{ts}Environment-managed settings{/ts}</strong></p>
      <p>{ts 1=$smschatOverridden|@count}%1 field(s) below are set via environment variables (CIVICRM_SMSCHAT_*) or civicrm.settings.php. They are read-only here; changing them requires updating the environment and redeploying.{/ts}</p>
    </div>
  {/if}

  <div class="help">
    <p>{ts 1=$smschatEnvironment}CiviCRM environment: <strong>%1</strong>.{/ts}
      {if $smschatLockdownActive}{ts}Lockdown is <strong>active</strong>: SMS Chat only sends to allowed recipients.{/ts}{else}{ts}Lockdown is not in force.{/ts}{/if}</p>
    <p>{ts}Sending also requires the <em>send SMS</em> permission and an active SMS provider; reading the thread only requires access to the contact's activities.{/ts}</p>
  </div>

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="top"}
  </div>

  {foreach from=$elementNames item=elementName}
    <div class="crm-section crm-section-{$elementName}">
      <div class="label">{$form.$elementName.label}</div>
      <div class="content">{$form.$elementName.html}</div>
      <div class="clear"></div>
    </div>
  {/foreach}

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
</div>
