<?php
namespace AdmidioPlugin\Provisioning\Service;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Email;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;
use Throwable;

/**
 * Tells the administrators of the organization by email that items of a target need attention -
 * at most once per target and day, and only if the plugin setting asks for it.
 */
class AttentionNotifier
{
    public function __construct(private readonly Database $db)
    {
    }

    public function notifyIfNeeded(Target $target): bool
    {
        global $gL10n, $gLogger;

        try {
            if (!ProvisioningPlugin::setting(ProvisioningPlugin::SETTING_NOTIFY_ADMINS)) {
                return false;
            }

            $count = (new Queue($this->db))->counts($target->getId())[Queue::STATE_ATTENTION];
            if ($count === 0 || !(new TargetStatus($this->db))->claimAttentionNotification($target->getId())) {
                return false;
            }

            $adminRoles = $this->db->queryPrepared(
                'SELECT rol_uuid FROM ' . TBL_ROLES . '
                  INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id
                  WHERE rol_administrator = true AND rol_valid = true AND cat_org_id = ?',
                array((int) $target->getValue('pvt_org_id'))
            )->fetchAll(\PDO::FETCH_COLUMN);
            if ($adminRoles === array()) {
                return false;
            }

            $plugin = PluginRegistry::get(ProvisioningPlugin::ID);
            $url = $plugin === null ? '' : SecurityUtils::encodeUrl(
                $plugin->getUrl('index.php'),
                array('mode' => 'status', 'uuid' => (string) $target->getValue('pvt_uuid'))
            );

            $email = new Email();
            $email->setSender();
            foreach ($adminRoles as $roleUuid) {
                $email->addRecipientsByRole((string) $roleUuid);
            }
            $email->setSubject($gL10n->get('PLG_PROVISIONING_ATTENTION_MAIL_SUBJECT', array($target->readableName())));
            $email->setText($gL10n->get('PLG_PROVISIONING_ATTENTION_MAIL_TEXT', array($target->readableName(), (string) $count, $url)));
            return $email->sendEmail();
        } catch (Throwable $exception) {
            if (isset($gLogger)) {
                $gLogger->warning('Provisioning: the attention notification could not be sent.', array('message' => $exception->getMessage()));
            }
            return false;
        }
    }
}
