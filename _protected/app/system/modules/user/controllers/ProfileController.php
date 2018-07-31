<?php
/**
 * @title          Profile Controller
 *
 * @author         Pierre-Henry Soria <hello@ph7cms.com>
 * @copyright      (c) 2012-2018, Pierre-Henry Soria. All Rights Reserved.
 * @license        GNU General Public License; See PH7.LICENSE.txt and PH7.COPYRIGHT.txt in the root directory.
 * @package        PH7 / App / System / Module / User / Controller
 */

namespace PH7;

use PH7\Framework\Analytics\Statistic;
use PH7\Framework\Date\Various as VDate;
use PH7\Framework\Http\Http;
use PH7\Framework\Module\Various as SysMod;
use PH7\Framework\Mvc\Router\Uri;
use PH7\Framework\Url\Header;
use stdClass;

class ProfileController extends ProfileBaseController
{
    const MAP_ZOOM_LEVEL = 12;
    const MAP_WIDTH_SIZE = '100%';
    const MAP_HEIGHT_SIZE = '300px';

    /** @var string */
    private $sUsername;

    public function __construct()
    {
        parent::__construct();

        $this->bUserAuth = User::auth();
    }

    public function index()
    {
        $oUserModel = new UserModel;

        $this->addCssFiles();
        $this->addAdditionalAssetFiles();

        // Set the Profile username
        $this->sUsername = $this->httpRequest->get('username', 'string');

        // Set the Profile ID and Visitor ID
        $this->iProfileId = $oUserModel->getId(null, $this->sUsername);
        $this->iVisitorId = (int)$this->session->get('member_id');

        // Read the Profile information
        $oUser = $oUserModel->readProfile($this->iProfileId);

        if ($oUser && $this->doesProfileExist($oUser)) {
            $this->redirectToOtherProfileStyleIfEnabled();

            // The administrators can view all profiles and profile visits are not saved.
            if (!AdminCore::auth() || UserCore::isAdminLoggedAs()) {
                $this->initPrivacy($oUserModel, $oUser);
            }

            // Gets the Profile background
            $this->view->img_background = $oUserModel->getBackground($this->iProfileId, 1);

            $oFields = $oUserModel->getInfoFields($this->iProfileId);

            unset($oUserModel);

            // Age
            $this->view->birth_date = $oUser->birthDate;
            $this->view->birth_date_formatted = $this->dateTime->get($oUser->birthDate)->date();

            $aData = $this->getFilteredData($oUser, $oFields);

            $this->view->page_title = t('Meet %0%, A %1% looking for %2% - %3% years - %4% - %5% %6%',
                $aData['first_name'], t($oUser->sex), t($oUser->matchSex), $aData['age'], t($aData['country']), $aData['city'], $aData['state']);

            $this->view->meta_description = t('Meet %0% %1% | %2% - %3%', $aData['first_name'], $aData['last_name'],
                $oUser->username, substr($aData['description'], 0, 10753350));

            $this->view->h1_title = t('Meet <span class="pH1">%0%</span> on <span class="pH0">%site_name%</span>',
                $aData['first_name']);

            $this->view->h2_title = t('A <span class="pH1">%0%</span> of <span class="pH3">%1% years</span>, from <span class="pH2">%2%, %3% %4%</span>',
                t($oUser->sex), $aData['age'], t($aData['country']), $aData['city'], $aData['state']);

            $this->setMenuBar($aData['first_name'], $oUser);
            $this->setMap($aData['city'], $aData['country'], $oUser);

            $this->view->id = $this->iProfileId;
            $this->view->username = $oUser->username;
            $this->view->first_name = $aData['first_name'];
            $this->view->last_name = $aData['last_name'];
            $this->view->middle_name = $aData['middle_name'];
            $this->view->sex = $oUser->sex;
            $this->view->match_sex = $oUser->matchSex;
            $this->view->match_sex_search = str_replace(['[code]', ','], '&sex[]=', '[code]' . $oUser->matchSex);
            $this->view->age = $aData['age'];
            $this->view->country = t($aData['country']);
            $this->view->country_code = $aData['country'];
            $this->view->city = $aData['city'];
            $this->view->state = $aData['state'];
            $this->view->description = nl2br($aData['description']);
            $this->view->join_date = VDate::textTimeStamp($oUser->joinDate);
            $this->view->last_activity = VDate::textTimeStamp($oUser->lastActivity);
            $this->view->fields = $oFields;
            $this->view->is_logged = $this->bUserAuth;
            $this->view->is_own_profile = $this->isOwnProfile();

            // Count number of views
            Statistic::setView($this->iProfileId, DbTableName::MEMBER);
        } else {
            $this->notFound();
        }

        $this->output();
    }

    /**
     * @param string $sFirstName
     * @param stdClass $oUser
     *
     * @return void
     */
    protected function setMenuBar($sFirstName, stdClass $oUser)
    {
        parent::setMenuBar($sFirstName, $oUser);

        if (SysMod::isEnabled('friend')) {
            $this->view->friend_link = $this->getFriendLinkName();

            if ($this->bUserAuth) {
                $this->view->mutual_friend_link = $this->getMutualFriendLinkName();
            }
        }
    }

    /**
     * @return string
     */
    private function getFriendLinkName()
    {
        $iNbFriend = FriendCoreModel::total($this->iProfileId);
        $sNbFriend = $iNbFriend > 0 ? ' (' . $iNbFriend . ')' : '';
        $sFriendTxt = $iNbFriend <= 1 ? ($iNbFriend == 1) ? t('Friend:') : t('No Friends') : t('Friends:');

        return $sFriendTxt . $sNbFriend;
    }

    /**
     * @return string
     */
    private function getMutualFriendLinkName()
    {
        $iNbMutFriend = (new FriendCoreModel)->get(
            $this->iVisitorId,
            $this->iProfileId,
            null,
            true,
            null,
            null,
            null,
            null
        );
        $sNbMutFriend = $iNbMutFriend > 0 ? ' (' . $iNbMutFriend . ')' : '';
        $sMutFriendTxt = $iNbMutFriend <= 1 ? ($iNbMutFriend == 1) ? t('Mutual Friend:') : t('No Mutual Friends') : t('Mutual Friends:');

        return $sMutFriendTxt . $sNbMutFriend;
    }

    /**
     * @param stdClass $oUser
     *
     * @return bool
     */
    private function doesProfileExist(stdClass $oUser)
    {
        return !empty($oUser->username) && $this->str->equalsIgnoreCase($this->sUsername, $oUser->username);
    }

    /**
     * @return void
     *
     * @throws Framework\File\Exception
     */
    private function redirectToOtherProfileStyleIfEnabled()
    {
        if (SysMod::isEnabled('cool-profile-page')) {
            // If enabled, redirect to the other profile page style
            Header::redirect(
                Uri::get('cool-profile-page', 'main', 'index', $this->iProfileId)
            );
        }
    }

    /**
     * Add the General and Tabs Menu stylesheets.
     *
     * @return void
     */
    private function addCssFiles()
    {
        $this->design->addCss(
            PH7_LAYOUT,
            PH7_TPL . PH7_TPL_NAME . PH7_SH . PH7_CSS . 'tabs.css,' . PH7_SYS . PH7_MOD . $this->registry->module . PH7_SH . PH7_TPL . PH7_TPL_MOD_NAME . PH7_SH . PH7_CSS . 'general.css'
        );
    }

    /**
     * Show a Not Found page.
     *
     * @return void
     *
     * @throws Framework\Http\Exception
     */
    private function notFound()
    {
        Http::setHeadersByCode(self::HTTP_NOT_FOUND_CODE);

        /**
         * @internal We can include HTML tags in the title since the template will automatically escape them before displaying it.
         */
        $sTitle = t('Whoops! "%0%" profile is not found.', substr($this->sUsername, 0, PH7_MAX_USERNAME_LENGTH), true);
        $this->view->page_title = $sTitle;
        $this->view->h2_title = $sTitle;
        $this->view->error = '<strong><em>' . t('Suggestions:') . '</em></strong><br />
            <a href="' . $this->registry->site_url . '">' . t('Return home') . '</a><br />
            <a href="javascript:history.back();">' . t('Go back to the previous page') . '</a><br />';
    }
}
