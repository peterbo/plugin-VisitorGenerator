<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\VisitorGenerator;

use Piwik\ArchiveProcessor\Rules;
use Piwik\Common;
use Piwik\Nonce;
use Piwik\Piwik;
use Piwik\Plugin\ControllerAdmin;
use Piwik\Plugins\SitesManager\API as SitesManagerAPI;
use Piwik\Plugins\VisitorGenerator\Generator\VisitsFake;
use Piwik\Plugins\VisitorGenerator\Generator\VisitsFromLogs;
use Piwik\SettingsServer;
use Piwik\Site;
use Piwik\Timer;
use Piwik\View;

/**
 *
 */
class Controller extends ControllerAdmin
{
    private $numFakeVisits = 300;

    public function index()
    {
        Piwik::checkUserHasSuperUserAccess();

        $sitesList = SitesManagerAPI::getInstance()->getSitesWithAdminAccess();

        $view = new View('@VisitorGenerator/index');
        $this->setBasicVariablesView($view);
        $view->sitesList = $sitesList;
        $view->nonce = Nonce::getNonce('VisitorGenerator.generate');

        $generatorFromLogs = new VisitsFromLogs();
        $view->countActionsPerRun = count($generatorFromLogs->getAccessLog()) + $this->numFakeVisits;
        $view->accessLogPath = $generatorFromLogs->getAccessLogPath();

        return $view->render();
    }

    public function generate()
    {
        Piwik::checkUserHasSuperUserAccess();
        $this->checkNonce();

        $daysToCompute = $this->checkDays();
        $idSite = Common::getRequestVar('idSite', false, 'string', $_POST);

        SettingsServer::setMaxExecutionTime(0);

        if ('all' == $idSite) {
            $idSites  = SitesManagerAPI::getInstance()->getSitesIdWithAtLeastViewAccess();
            $siteName = Piwik::translate('General_MultiSitesSummary');
        } else {
            // get idSite from POST with fallback to GET
            $idSite   = Common::getRequestVar('idSite', false, 'int', $_GET);
            $idSite   = Common::getRequestVar('idSite', $idSite, 'int', $_POST);
            $idSites  = array($idSite);
            $siteName = Site::getNameFor($idSite);
        }

        $timer = new Timer;
        $time  = time() - ($daysToCompute - 1) * 86400;

        $nbActionsTotal = 0;
        while ($time <= time()) {

            $fromLogs = new VisitsFromLogs();
            foreach ($idSites as $idSite) {
                $nbActionsTotal += $fromLogs->generate($time, $idSite);
            }

            $fakeVisits = new VisitsFake();
            foreach ($idSites as $idSite) {
                $nbActionsTotal += $fakeVisits->generate($time, $idSite, $this->numFakeVisits);
            }

            $time += 86400;
        }

        $browserArchiving = Rules::isBrowserTriggerEnabled();

        $view = new View('@VisitorGenerator/generate');
        $this->setBasicVariablesView($view);
        $view->browserArchivingEnabled = $browserArchiving;
        $view->timer = $timer;
        $view->days = $daysToCompute;
        $view->nbActionsTotal = $nbActionsTotal;
        $view->nbRequestsPerSec = round($nbActionsTotal / $timer->getTime(), 0);
        $view->siteName = $siteName;

        return $view->render();
    }

    private function checkDays()
    {
        $daysToCompute = Common::getRequestVar('daysToCompute', false, 'int');

        if ($daysToCompute < 1) {
            throw new \Exception('Days to compute must be greater or equal to 1.');
        }

        return $daysToCompute;
    }

    private function checkNonce()
    {
        $nonce = Common::getRequestVar('form_nonce', '', 'string', $_POST);

        if (Common::getRequestVar('choice', 'no') != 'yes' ||
            !Nonce::verifyNonce('VisitorGenerator.generate', $nonce)
        ) {
            Piwik::redirectToModule('VisitorGenerator', 'index');
        }

        Nonce::discardNonce('VisitorGenerator.generate');
    }

}
