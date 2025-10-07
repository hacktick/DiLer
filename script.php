<?php

/**
 * DiLer package installation script install/uninstall/preinstall/postinstall
 * @package        DiLer.Administrator
 * @subpackage    pkg_diler
 * @filesource
 * @copyright    Copyright (C) 2013-2015 digitale-lernumgebung.de. All rights reserved.
 * @license        GNU Affero General Public License version 3 or later; see media/com_diler/images/agpl-3.0.txt
 */
// No direct access to this file
defined('_JEXEC') or die('Restricted access');

use Audivisa\Component\DiLer\Administrator\Helper\MVCHelper;
use Audivisa\Component\DiLer\Site\Helper\VersionHelper;
use Audivisa\Component\DiLer\Site\Model\UnblockDigluUsersModel;
use DiLer\Core\Pep\PepMethod;
use DiLer\Lang\DText;
use Joomla\CMS\Access\Rules;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Table\Menu;
use Joomla\CMS\Table\Table;
use Audivisa\Component\DiLer\Administrator\Helper\DiLerSettings;


JLoader::register('DilerHelper', JPATH_ROOT . '/administrator/components/com_diler/helpers/diler.php');
JLoader::register('DilerHelperUser', JPATH_ROOT . '/components/com_diler/helpers/user.php');
JLoader::register('DilerParams', JPATH_ROOT . '/components/com_diler/helpers/dilerparams.php');

class pkg_dilerInstallerScript
{
    const MINIMUM_REQUIRED_PHP_VERSION = '8.1.0';
    const MINIMUM_REQUIRED_DILER_VERSION = '6.6.0';

    /**
     * Called on installation
     *
     * @param InstallerAdapter $adapter The object responsible for running this script
     *
     * @return  boolean  True on success
     */
    public function install(InstallerAdapter $adapter)
    {
        return true;
    }


    /**
     * Called on uninstallation
     *
     * @param InstallerAdapter $adapter The object responsible for running this script
     */
    public function uninstall(InstallerAdapter $adapter)
    {
        return true;
    }

    /**
     * Called on update
     *
     * @param InstallerAdapter $adapter The object responsible for running this script
     *
     * @return  boolean  True on success
     */
    public function update(InstallerAdapter $adapter)
    {
        // $parent is the class calling this method
        echo '<div><p>' . Text::sprintf('PKG_DILER_UPDATE_TEXT', $adapter->getManifest()->version) . '</p>';
    }

    /**
     * Called before any type of action
     *
     * @param string $route Which action is happening (install|uninstall|discover_install|update)
     * @param InstallerAdapter $adapter The object responsible for running this script
     *
     * @return  boolean  True on success
     */
    public function preflight($route, InstallerAdapter $adapter)
    {
        if (!$this->checkValidDilerVersion())
            return false;

        if (!$this->checkRequiredPhpVersion())
            return false;

        $version = $this->getDilerVersion();
        $this->disableEprivacyExtensions();
        if (version_compare($version, '6.13.0', '<')) {
            Log::add('Delete users that do not exist in Joomla users', Log::INFO, 'Update');
            $this->deleteDilerUsersThatDontExistInJoomlaUsers();
        }
        $this->FixWrongeDilerRoles();
        $this->fixMissedUsergroups();
        $this->addLogger();
        $this->loadUpdateLanguageStrings($adapter);

        Log::add('Executing preflight(): Current Version = ' . $version, Log::INFO, 'Update');

        $this->convertParentStudentData();

        if (!ComponentHelper::getParams('com_diler')->get('other_group_parent_id', '')) {
            Log::add('Start: Creating other groups parent record.', Log::INFO, 'Update');
            $this->createOtherGroupsParent();
            Log::add('Finish: Creating other groups parent record.', Log::INFO, 'Update');
        }

        if (!$this->isAtLeastOneOtherGroupCategory()) {
            Log::add('Start: Creating category for other groups.', Log::INFO, 'Update');
            $this->createOtherGroupCategory();
            Log::add('Finish: Creating category for other groups.', Log::INFO, 'Update');
        }

        if (version_compare($version, '6.10.3', 'lt')) {
            $this->allowViewAccessToGroupsAndTexterForDilerUsersGroups();
        }

        if (version_compare($version, '6.11.0', 'lt')) {
            Log::add('Start: change level publish down to zero on update to 6.11.0', Log::INFO, 'Update');
            $this->setLevelPublishDownToZero();
            Log::add('Finish: change level publish down to zero on update to 6.11.0', Log::INFO, 'Update');
        }
        if (version_compare($version, '6.11.0', 'lt')) {
            Log::add('Remove previously saved diler groups created information', Log::INFO, 'Update');
            $this->removeCreatedInfoOfDilerGroups();
        }
        if (version_compare($version, '6.11.11', 'lt')) {
            Log::add('Update missing student ID in stored reports for Mark Reports', Log::INFO, 'Update');
            $this->updateMissingStudentIdsForMarkStoredReports();
        }

	    if (version_compare($version, '7.0.1', '<='))
	    {
		    Log::add('Set default value for created in #__diler_studentrecord_history table', Log::INFO, 'Update');
		    $this->setDefaultVeluForCreatedInSchoolHistoryTable();
	    }
        if (version_compare($version, '7.0.3.1', '<'))
        {
            Log::add('Add column #__dilerreg_users.standard_class_schedule if not exist', Log::INFO, 'Update');
            $this->addColumnStandardClassScheduleIfNotExist();
        }
    }

    private function removeWrongSqlFile(): void
    {
        $sqlFilePath = JPATH_ROOT . '\administrator\components\com_diler\sql\updates\mysql\6.12.0.sql';
        if (File::exists($sqlFilePath)) {
            File::delete($sqlFilePath);
        }
    }

    private function setDefaultWeekdays(): void
    {
        $params = ComponentHelper::getParams('com_diler');
        $params->set('class_week_days', ['2', '3', '4', '5', '6']);
        $this->saveDilerParams($params);
    }

    private function setDefaultParticularsInComponentParams(): void
    {
        $params = ComponentHelper::getParams('com_diler');
        $params->set('student_particulars', 'Brillenträger||Hortkind');
        $this->saveDilerParams($params);
    }

    /**
     * @since 6.11.6
     */
    private function loadUpdateLanguageStrings(InstallerAdapter $parent): void
    {
        $sourcePath = $parent->getParent()->getPath('source');
        $language = Factory::getLanguage();
        $language->load('pkg_diler.sys', $sourcePath);
    }

    private function removeCreatedInfoOfDilerGroups(): void
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__diler_group');
        $query->set('created = NULL');
        $query->set('created_by = NULL');
        $db->setQuery($query)->execute();
    }

    /**
     * Disable eprivacy modules and plugins during update.
     */
    private function disableEprivacyExtensions()
    {
        $db = Factory::getDbo();
        $elements = array('eprivacy', 'eprivacygeoip', 'mod_eprivacy', 'pkg_eprivacy');
        $elements = array_map(function ($item) use ($db) {
            return $db->quote($item);
        }, $elements);
        $query = $db->getQuery(true)
            ->update('#__extensions')
            ->set('enabled = 0')
            ->where($db->quoteName('element') . ' IN (' . implode(",", $elements) . ')');
        $db->setQuery($query)->execute();
    }

    /**
     * Checks whether the php version meet minimum version return true or false with warning message.
     *
     * @return bool true if php version meet minimum version, otherwise false.
     * @throws Exception
     */
    private function checkRequiredPhpVersion()
    {
        if (strnatcmp(phpversion(), self::MINIMUM_REQUIRED_PHP_VERSION) < 0) {
            $errorMessage = DText::sprintf('WARNING_MESSAGE_IF_NOT_MEET_PHP_VERSION', self::MINIMUM_REQUIRED_PHP_VERSION);
            Factory::getApplication()->enqueueMessage($errorMessage, 'error');

            return false;
        }

        return true;
    }

    /**
     * Fill #__dilerreg_users role column with diler user role.
     */
    private function FixWrongeDilerRoles()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('user_id, role');
        $query->from($db->quoteName('#__dilerreg_users'));
        $db->setQuery($query);
        $dilerUsers = $db->loadObjectList();
        foreach ($dilerUsers as $dilerUser) {
            $dilerRole = DilerHelperUser::getDilerRole($dilerUser->user_id);
            if ($dilerUser->role != $dilerRole && $dilerRole !== false) {
                $updateUserQuery = $db->getQuery(true)
                    ->update($db->quoteName('#__dilerreg_users'))
                    ->where('user_id = ' . (int)$dilerUser->user_id)
                    ->set('role = ' . $db->quote($dilerRole));
                $db->setQuery($updateUserQuery)->execute();
            }
        }
    }

    /**
     * @since 6.11.5
     */
    private function fixMissedUsergroups(): void
    {
        Log::add('Start: fixing missed user groups.', Log::INFO, 'Update');
        $usersMissedGroups = $this->getUsersMissedGroups();
        Log::add('Info: count of missed user groups: ' . count($usersMissedGroups), Log::INFO, 'Update');
        $this->assignProperGroupIdBasedOnRole($usersMissedGroups);
        Log::add('Finish: fixing missed user groups.', Log::INFO, 'Update');
    }

    /**
     * @since 6.11.5
     */
    private function assignProperGroupIdBasedOnRole(array $users): void
    {
        if (!$users)
            return;

        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->insert('#__user_usergroup_map');
        $query->columns('user_id, group_id');
        $values = array();

        $mainGroups = $this->getDirectMainGroupsOfDilerUsergroups();
        $minStudentGroupId = min($mainGroups['student']);
        $minTeacherGroupId = min($mainGroups['teacher']);
        $minParentGroupId = min($mainGroups['parent']);

        foreach ($users as $user) {
            if ($user->role == 'student' && $minStudentGroupId)
                $groupId = $minStudentGroupId;
            elseif ($user->role == 'teacher' && $minTeacherGroupId)
                $groupId = $minTeacherGroupId;
            elseif ($user->role == 'parent' && $minParentGroupId)
                $groupId = $minParentGroupId;
            else
                continue;

            $values[] = $user->user_id . ',' . $groupId;
        }
        $query->values($values);

        $db->setQuery($query)->execute();
    }

    /**
     * @since 6.11.5
     */
    private function getDirectMainGroupsOfDilerUsergroups(): array
    {
        $params = ComponentHelper::getParams('com_diler');
        $mainGroups = array(
            'student' => $params->get('student_group_ids', array()),
            'teacher' => $params->get('teacher_group_ids', array()),
            'parent' => $params->get('parent_group_ids', array())
        );

        $dilerUsergroupsId = $this->getDilerUsergroupsId();
        if (!$dilerUsergroupsId)
            return $mainGroups;

        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id');
        $query->from('#__usergroups');
        $query->where('parent_id = ' . $dilerUsergroupsId);
        $directChilds = $db->setQuery($query)->loadColumn();

        return array(
            'student' => array_intersect($directChilds, $mainGroups['student']),
            'teacher' => array_intersect($directChilds, $mainGroups['teacher']),
            'parent' => array_intersect($directChilds, $mainGroups['parent'])
        );
    }

    /**
     * @since 6.11.5
     */
    private function getDilerUsergroupsId(): int
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id');
        $query->from('#__usergroups');
        $query->where('title = "DiLer user groups"');

        return (int)$db->setQuery($query)->loadResult();
    }

    /**
     * @since 6.11.5
     */
    private function getUsersMissedGroups(): array
    {
        $usersWithProperGroup = $this->getUsersWithProperGroup();
        return $this->getUsersWithoutProperGroup($usersWithProperGroup);
    }

    /**
     * @since 6.11.5
     */
    private function getUsersWithProperGroup(): array
    {
        $params = ComponentHelper::getParams('com_diler');
        $mainGroups = array_merge(
            $params->get('teacher_group_ids', array()),
            $params->get('student_group_ids', array()),
            $params->get('parent_group_ids', array())
        );

        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('user_id');
        $query->from('#__user_usergroup_map AS ugm');
        $query->where('ugm.group_id IN (' . implode(', ', $mainGroups) . ')');
        $query->group('user_id');

        return $db->setQuery($query)->loadColumn();
    }

    /**
     * @since 6.11.5
     */
    private function getUsersWithoutProperGroup($usersWithProperGroup): array
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true);
        $query->select('role, user_id');
        $query->from('#__dilerreg_users AS du');
        $query->where('du.role IN ("student", "teacher", "parent")');
        $query->where('du.user_id NOT IN (' . implode(',', $usersWithProperGroup) . ')');

        return $db->setQuery($query)->loadObjectList();
    }

    /**
     * Called after any type of action
     *
     * @param string $route Which action is happening (install|uninstall|discover_install|update)
     * @param InstallerAdapter $adapter The object responsible for running this script
     *
     * @return  boolean  True on success
     */
    public function postflight($route, InstallerAdapter $adapter)
    {
        $this->addLogger();
        $xmlContent = file_get_contents(JPATH_ROOT . '/administrator/manifests/packages/pkg_diler.xml', true);
        $xml = new SimpleXMLElement($xmlContent);
        $version = (string)$xml->version;
        $edition = (string)$xml->version['data-edition'];
        Log::add('Executing postflight(): Current Version = ' . $version, Log::INFO, 'Update');

        $this->fix36LogoutBug();
        $this->deleteRemovedFiles();
        if ($edition == 'PE')
            $this->copyPhpseclib();
        $this->copyDompdf();
        $this->copyAdmintemplate();
        $this->copyAdminLanguages();
        $this->deleteDilerregAdminMenu();

        // Remove PE files if this is a downgrade to CE
        Log::add('Checking for PE to CE downgrade.', Log::INFO, 'Update');
        if ($edition != 'PE') {
            Log::add('Downgrade found. Converting to CE.', Log::INFO, 'Update');
            $this->convertToCE();
            $this->removeOldUpdateSource();
            $this->removePermissions(array('bulletin.desk.view', 'wiki.view'));
            $effectedComponents = array(
                array(
                    'name' => 'com_dpcalendar',
                    'param' => 'downloadid',
                ),
                array(
                    'name' => 'com_akeeba',
                    'param' => 'update_dlid',
                ),
            );
            foreach ($effectedComponents as $component) {
                $this->removeComponentParam($component['name'], $component['param']);
            };
        }

        $this->createSchoolYearRow();
        $this->updateV61GroupSubjectSectionMapTable();
        $this->addGroupIdToRegCodes();

        $this->combineSalutationAndTitle();

        Log::add('Printing postflight message.', Log::INFO, 'Update');
        echo '<p>' . Text::_('PKG_DILER_POSTFLIGHT_' . strtoupper($route) . '_TEXT') . '</p>' . '<p>' . Text::_('PKG_DILER_INSTALL_TEXT') . '</p></div></div>' . '<hr><a class="btn btn-primary" href="index.php?option=com_diler" title="DiLer">' . Text::_('PKG_DILER_INSTALL_FINISHED_LINK') . '</a><hr>' . '</div>';

        $this->updateWikiAuthJoomlaSSOPlugin($adapter);
        $this->deleteWikiDoPlugin();

        if (version_compare($version, '6.10.2', 'ge')) {
            Log::add('Start: moving remark media files from task solution folder to new path inside remark folder on update to 6.10.2', Log::INFO, 'Update');
            $this->moveRemarkMedias();
            Log::add('Finish: moving remark media files from task solution folder to new path inside remark folder on update to 6.10.2', Log::INFO, 'Update');
        }

        Log::add('Start publish Plugin: System - DiLer Mailer', Log::INFO, 'Update');
        $this->publishPluginSystemDilerMailer();
        Log::add('End publish Plugin: System - DiLer Mailer', Log::INFO, 'Update');

        Log::add('Start publish Plugin: System - DiLer Link Modifier', Log::INFO, 'Update');
        $this->publishPluginSystemDilerLinkModifier();
        Log::add('End publish Plugin: System - DiLer Link Modifier', Log::INFO, 'Update');

        Log::add('Start publish Plugin: System - DiLer Override Joomla core Http Curl Transporter', Log::INFO, 'Update');
        $this->publishPluginSystemDilerOverideCurlTransporter();
        Log::add('End publish Plugin: System - DiLer Override Joomla core Http Curl Transporter', Log::INFO, 'Update');

	    Log::add('Start publish Plugin: System ExportCsv', Log::INFO, 'Update');
	    $this->publishPluginSystemExportCsv();
	    Log::add('End publish publish Plugin: System ExportCsv', Log::INFO, 'Update');

        if (!ComponentHelper::getParams('com_diler')->get('studentRecordHomeSchooling', '')) {
            Log::add('Start: Add category for Home Schooling on update to 6.11.0', Log::INFO, 'Update');
            $this->createHomeSchoolingCategory();
            Log::add('Finish: Add category for Home Schooling on update to 6.11.0', Log::INFO, 'Update');
        }

        if (version_compare($version, '6.11.10', 'ge')) {
            Log::add('Start: remove unsafe HTML from personal user fields', Log::INFO, 'Update');
            $this->cleanDilerregUsersTableNoteFields();
            $this->cleanDilerTeacherNotesNoteField();
            Log::add('Finish: remove unsafe HTML from personal user fields', Log::INFO, 'Update');

            $this->setDefaultWeekdays();
            Log::add('Move previously saved rooms to default building', Log::INFO, 'Update');
            $this->createDefaultBuildingHouseA();
        }

        if (version_compare($version, '6.11.12', '==')) {
            Log::add('Set learning group id for stored reports', Log::INFO, 'Update');
            $this->setLearningGroupForStoredReports();
        }

        if (version_compare($version, '6.11.14', '==')) {
            Log::add('Update missing in #__diler_cloud.created', Log::INFO, 'Update');
            $this->assignCloudFilesCreatedDateFromDisk();
        }

        if (version_compare($version, '6.11.16', '==')) {
            Log::add('Set proper DB field type for #__dilerreg_users.dob', Log::INFO, 'Update');
            $this->fixDobFieldInUsersTable();
        }

        if (version_compare($version, '6.11.17', '==')) {
            Log::add('Start: Add default values for student particulars', Log::INFO, 'Update');
            $this->setDefaultParticularsInComponentParams();
        }

        if ($edition == 'PE') {
            Log::add('Start: Set new transcoder details', Log::INFO, 'Update');
            $this->setTranscoderServerLoginDetails('transcoder.digitale-lernumgebung.de', 'dilertranscoder', 'cfcm7R7qBk3g@bRG');
            Log::add('Finish: Set new transcoder details', Log::INFO, 'Update');
        }

        if (version_compare($version, '6.11.17', '==')) {
            Log::add('Add Registered users to Default JCE profile', Log::INFO, 'Update');
            $this->setRegisteredUsersToDefaultJCEEditorProfiles();
        }

        if (version_compare($version, '6.11.17', '==')) {
            Log::add('Populate #__dpcalendar_caldav_principals with fresh data', Log::INFO, 'Update');
            BaseDatabaseModel::addIncludePath(JPATH_ROOT . '/administrator/components/com_diler/models/');
            /** @var DilerModelSampledata $sampleDataModel */
            $sampleDataModel = MVCHelper::factory()->createModel('Sampledata', 'Administrator');
            $sampleDataModel->refreshDPCalendarCaldavPrincipalsTable();
        }

        if ($this->doWeNeedToPopulateDobFieldFromDobObsolete()) {
            Log::add('Populate again #__dilerreg_users.dob field from dob_obsolete', Log::INFO, 'Update');
            $this->fixDobFieldInUsersTable();
            $this->removeDobNotPopulatedField();
        }

        if (version_compare($version, '6.12.0', '>=')) {
            Log::add('Populate new table #__diler_pep_methods ', Log::INFO, 'Update');
            $this->populatePepMethodsTable();
        }

        if (version_compare($version, '6.12.0', '>=')) {
            $id = $this->getPepDevelopmentAreaDefaultId();
            Log::add('#__diler_pep_assessments.development_area_id with ID of inserted record. ', Log::INFO, 'Update');
            $this->updatePepAssessmentsWithPepDevelopmentAreaId($id);

            Log::add('Remove student_id and subject_group_id from #__diler_pep table', Log::INFO, 'Update');
            $this->removeStudentIdAndGroupIdFromPepTable();
        }
        if (version_compare($version, '6.13.0', '==') && DilerHelperUser::isDiglu()) {
            Log::add('Start populating #__diler_school.principel_user_id', Log::INFO, 'Update');
            $this->populateSchoolPrincipalUserId();
            Log::add('End populating #__diler_school.principel_user_id', Log::INFO, 'Update');
        }

        if (version_compare($version, '6.13.0', '==')) {
            Log::add('Add all countries and states to #__diler_country and #__diler_state', Log::INFO, 'Update');
            $this->updateCountriesAndStates();
        }

        if (version_compare($version, '6.13.2', '==')) {
            Log::add('Set proper DB field type for #__diler_class_schedule_time_slot and #__diler_group_schedule columns(start_date, end_date)', Log::INFO, 'Update');
            //converts the start_time and end_time to date type from varchar
            $this->fixStartAndEndTimeIn('#__diler_class_schedule_time_slot');
            $this->fixStartAndEndTimeIn('#__diler_group_schedule');
        }

        if (version_compare($version, '6.14.2', '==')) {
            Log::add('Move region teachers from #__diler_student_region_teacher_at_time_of_enrollment to #__diler_student_region_teacher_at_time_of_enrollment', Log::INFO, 'Update');
            //converts the start_time and end_time to date type from varchar
            $this->populateNewTableForRegionTeachersAtTheTimeOfEnrollment();
        }

        if (version_compare($version, '6.15.0', '==')) {
            Log::add('Set publish_up and publish_down fields to 0000-00-00 00:00:00 date in #__diler_subject table', Log::INFO, 'Update');
            $this->setSubjectPublishUpAndPublishDownToZeroDate();

            Log::add('Set enroll_start and enroll_end to UTC-0 in #__diler_user_school_history_table', Log::INFO, 'Update');
            $this->fixSchoolHistoryEndAndStartDays();
        }

        $this->ifThereIsMissingDataIGlobalConfigurationShowWarningMessages();

        Log::add('Delete SQL files older than current version', Log::INFO, 'Update');
        $this->deleteSqlFilesOlderThanCurrentVersion();

        if (version_compare($version, '7.0.3.1', '=='))
        {
            Log::add('Remove pep methods from diler core libraries', Log::INFO, 'Update');
            $this->deletePepMethodsFromLibraries();
        }

		if (version_compare($version, '7.0.4', '>'))
		{
			Log::add('Successfully deleted isis template', Log::INFO, 'Update');
			$this->deleteIsisTemplate();
		}

		Log::add('Change wiki menu link from index.php?/wiki  to /wiki', Log::INFO, 'Update');
		$this->changeWikiMenuLink();

		Log::add('Enabled Joomla core Multi-factor Authentication and uninstalled Loginguard ', Log::INFO, 'Update');
		$this->updateMultifactorAuthentication();


	    if (version_compare($version, '7.1.8.5', '>='))
	    {
			Log::add('Added new folder for Term and Conditions.', Log::INFO, 'Update');
			$this->importTermsAndConditions();
	    }

        if (DilerHelperUser::isDiglu() && version_compare($version, '7.1.8', '=='))
        {
            $this->insertDigluBlockedUser();
            $this->blockParentAndStudentUsers();
        }
        $this->changeAccessAndParentIdForTypeOfGuardians();
	}

    private function getJCEDefaultTypes()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('types');
        $query->from('#__wf_profiles');
        $query->where('name LIKE "Default"');
        return $db->setQuery($query)->loadResult();
    }

    private function getUserGroupIdWhereRegistered()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id');
        $query->from('#__usergroups');
        $query->where('title IN ("Registriert", "Registered")');
        return $db->setQuery($query)->loadColumn();
    }

    private function updateJCEDefaultTypes($typesForUpdate)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__wf_profiles');
        $query->set('types =' . $db->quote($typesForUpdate));
        $query->where('name LIKE "Default"');
        $db->setQuery($query)->execute();
    }

    private function setRegisteredUsersToDefaultJCEEditorProfiles()
    {
        $userGroups = $this->getUserGroupIdWhereRegistered();
        $jceDefaultTypes = $this->getJCEDefaultTypes();
        $types = explode(',', $jceDefaultTypes);
        if (!$jceDefaultTypes)
            return;

        foreach ($userGroups as $userGroup) {
            if (!in_array($userGroup, $types))
                $types[] = $userGroup;
        }

        $typesForUpdate = implode(',', $types);
        $this->updateJCEDefaultTypes($typesForUpdate);
    }

    private function setLearningGroupForStoredReports()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__diler_stored_report as sr');
        $query->innerJoin('#__users AS u ON u.id = sr.student_id');
        $query->innerJoin('#__user_usergroup_map AS ugm ON u.id = ugm.user_id');
        $query->innerJoin('#__usergroups AS ug ON ug.id = ugm.group_id');
        $query->set('learning_group_id = ug.id');
        $query->where('sr.learning_group_id is NULL');
        $query->where('ug.parent_id = ' . DilerParams::init()->getLearningGroupParentId());

        $db->setQuery($query)->execute();
    }

    private function getMarkStoredReportsWithoutStudentId()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('file_name');
        $query->from('#__diler_stored_report');
        $query->where('student_id = 0');
        $query->where('file_name LIKE "marks_%"');
        return $db->setQuery($query)->loadColumn();
    }


    private function getStudentFullNameConbinationsFromFileName($fileName)
    {
        preg_match('/marks_([^_]*)_([^_]*)/i', $fileName, $matches);

        $combinations = array();
        if (isset($matches[2]) && $matches[2]) {
            $fullName = $matches[2];
            $numberOfMinus = substr_count($fullName, '-');
            $combinations[] = $fullName;
            for ($x = 1; $x <= $numberOfMinus; $x++) {
                $pos = strpos($fullName, '-', $x);
                if ($pos !== false) {
                    $fullName = substr_replace($fullName, " ", $pos, 1);
                    $combinations[] = $fullName;
                }
            }
            $fullName = $matches[2];
            for ($x = 1; $x <= $numberOfMinus; $x++) {
                $pos = strpos($fullName, '-', strpos($fullName, '-') + 1);
                if ($pos !== false) {
                    $fullName = substr_replace($fullName, " ", $pos, 1);
                    $combinations[] = $fullName;
                }
            }
        }

        return $combinations;
    }

    private function updateMissingStudentIdsForMarkStoredReports()
    {
        $recordsWithoutStudentId = $this->getMarkStoredReportsWithoutStudentId();
        foreach ($recordsWithoutStudentId as $fileName) {
            $studentNames = $this->getStudentFullNameConbinationsFromFileName($fileName);
            if ($studentNames) {
                $studentId = $this->getStudentIdBasedOnPossibleStudentNames($studentNames);
                if ($studentId)
                    $this->updateStudentIdForMarkStoredReport($studentId, $fileName);
            }
        }
    }

    private function getStudentIdBasedOnPossibleStudentNames($studentNames)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('user_id');
        $query->from('#__dilerreg_users as du');
        foreach ($studentNames as $combination)
            $query->where('CONCAT(du.forename, " ", du.surname) like ' . $db->quote($combination), 'OR');


        return $db->setQuery($query)->loadResult();
    }

    public function updateStudentIdForMarkStoredReport($studentId, $fileName)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__diler_stored_report');
        $query->where('file_name = ' . $db->quote($fileName));
        $query->set('student_id = ' . (int)$studentId);

        $db->setQuery($query)->execute();
    }

    /**
     * @since 6.11.5
     */
    private function checkValidDilerVersion()
    {
        $version = $this->getDilerVersion();
        if (version_compare($version, self::MINIMUM_REQUIRED_DILER_VERSION, 'lt')) {
            $errorMessage = DText::sprintf('WARNING_MESSAGE_IF_NOT_MEET_DILER_VERSION', self::MINIMUM_REQUIRED_DILER_VERSION);
            Factory::getApplication()->enqueueMessage($errorMessage, 'error');

            return false;
        }
        return true;
    }

    /**
     * @since 6.11.0
     */
    private function getOtherGroupsParentId()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id');
        $query->from('#__usergroups');
        $query->where('title = ' . $db->quote('Sonstige Gruppen'));

        return $db->setQuery($query)->loadResult();
    }

    /**
     * @since 6.11.0
     */
    private function createOtherGroupsParent(): void
    {
        // Use the parent_id of the "Learning Group" for the Sonstige Gruppen parent_id
        $options = ComponentHelper::getParams('com_diler');
        $lgParentId = $options->get('learning_group_parent_id');

        AdminModel::addIncludePath(JPATH_ROOT . '/administrator/components/com_users/models/');
        $usergroupModel = MVCHelper::factory()->createModel('Group', 'Administrator');
        $lgParent = $usergroupModel->getItem($lgParentId);

        $otherGroupParentData = array(
            'id' => 0,
            'title' => 'Sonstige Gruppen',
            'parent_id' => $lgParent->parent_id,
            'lft' => $lgParent->lft,
            'rgt' => $lgParent->rgt,
        );
        $otherGroupParentId = $this->getOtherGroupsParentId();
        if (!$otherGroupParentId) {
            $otherGroupParentId = $this->createNewJoomlaUserGroup($otherGroupParentData);
        }

        $this->SetDefaultOtherGroupParentIdOptions($otherGroupParentId);
    }

    /**
     * @since 6.11.0
     */
    private function SetDefaultOtherGroupParentIdOptions($parentId): void
    {
        $params = ComponentHelper::getParams('com_diler');
        $params->set('other_group_parent_id', $parentId);
        $this->saveDilerParams($params);
    }

    /**
     * @since 6.11.0
     */
    private function isAtLeastOneOtherGroupCategory()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*');
        $query->from('#__categories');
        $query->where('extension = "com_diler.group"');
        $query->where("params LIKE '%" . '"group_type":"4"' . "%'");

        return $db->setQuery($query)->loadObjectList();
    }

    /**
     * @since 6.11.0
     */
    private function createOtherGroupCategory(): void
    {
        Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_categories/tables');
        BaseDatabaseModel::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_categories/models');
        /** @var CategoriesModelCategory $categoryModel */
        $categoryModel = MVCHelper::factory()->createModel('Category', 'Administrator');

        $access = $this->getDilerUserAccess();
        $data = array(
            'id' => 0,
            'title' => 'Sonstige Gruppen',
            'parent_id' => 1,
            'level' => '1',
            'extension' => 'com_diler.group',
            'published' => 1,
            'access' => $access,
            'language' => '*',
            'associations' => array(),
            'params' => array('group_type' => '4'),
        );

        $categoryModel->save($data);
    }

    /**
     * @since 6.11.0
     */
    private function getDilerUserAccess($title = 'Diler User'): int
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id');
        $query->from('#__viewlevels');
        $query->where('title = ' . $db->quote($title));

        $id = $db->setQuery($query)->loadResult();

        return $id ? $id : 1;
    }

    private function setLevelPublishDownToZero(): void
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__diler_level');
        $query->set('publish_down = NULL');
        $db->setQuery($query)->execute();
    }

    private function createHomeSchoolingCategory()
    {
        Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_categories/tables');
        BaseDatabaseModel::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_categories/models');
        /** @var CategoriesModelCategory $categoryModel */
        $categoryModel = MVCHelper::factory()->createModel('Category', 'Administrator');
        $category = array(
            'parent_id' => '1',
            'title' => 'Home Schooling',
            'level' => '1',
            'extension' => 'com_diler.studentrecord',
            'published' => 1,
            'access' => 1,
            'language' => '*',
            'associations' => array(),
        );
        $categoryModel->save($category);

        $db = Factory::getDbo();
        $homeSchoolingIdQuery = $db->getQuery(true)
            ->select('id')
            ->from('#__categories')
            ->where($db->quoteName('title') . ' = ' . $db->quote('Home Schooling'))
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_diler.studentrecord'));
        $homeSchoolingId = $db->setQuery($homeSchoolingIdQuery)->loadResult();

        $params = ComponentHelper::getParams('com_diler');
        $params->set('studentRecordHomeSchooling', $homeSchoolingId);

        $updateParamsQuery = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote($params->toString()))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_diler'));
        $db->setQuery($updateParamsQuery)->execute();
    }

    private function publishPluginSystemDilerMailer()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__extensions');
        $query->set('enabled = 1');
        $query->where("element = 'dilermailer'");
        $query->where("type = 'plugin'");
        $query->where("folder = 'system'");

        $db->setQuery($query)->execute();
    }

    private function publishPluginSystemDilerLinkModifier()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__extensions');
        $query->set('enabled = 1');
        $query->where("element = 'dilerlinkmodifier'");
        $query->where("type = 'plugin'");
        $query->where("folder = 'system'");

        $db->setQuery($query)->execute();
    }

	private function publishPluginSystemExportCsv()
	{
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		$query->update('#__extensions');
		$query->set('enabled = 1');
		$query->where("element = 'exportcsv'");
		$query->where("type = 'plugin'");
		$query->where("folder = 'system'");

		$db->setQuery($query)->execute();
	}

    private function publishPluginSystemDilerOverideCurlTransporter()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__extensions');
        $query->set('enabled = 1');
        $query->where("element = 'dileroveridecurltransporter'");
        $query->where("type = 'plugin'");
        $query->where("folder = 'system'");

        $db->setQuery($query)->execute();
    }

    /**
     * Moving remark medias from task solution folder to new path inside remark folder.
     * @since 6.10.2
     */
    private function moveRemarkMedias()
    {
        $db = Factory::getDbo();
        $remarkMediasQuery = $db->getQuery(true)
            ->select('media.path')
            ->from('#__diler_grid_media AS media')
            ->leftJoin('#__diler_activity_task_grid_media_map AS media_map ON media_map.grid_media_id = media.id')
            ->where('media_map.is_remark = 1');
        $remarkMediasPath = $db->setQuery($remarkMediasQuery)->loadColumn();

        foreach ($remarkMediasPath as $remarkMediaPath) {
            $dilerMediaRootFolder = DilerHelperUser::getRootFileFolder();
            $fullPath = $dilerMediaRootFolder . $remarkMediaPath;
            $folderName = dirname($fullPath);
            if (strpos($folderName, 'remark') !== false || !File::exists($fullPath))
                continue;
            $destinationFolder = $folderName . '/remark/';
            Folder::create($destinationFolder);
            $fileName = basename($fullPath);
            File::move($fullPath, $destinationFolder . $fileName);
        }
    }

    private function updateWikiAuthJoomlaSSOPlugin(InstallerAdapter $parent)
    {
        $wikiPath = JPATH_ROOT . '/wiki';
        if (!Folder::exists($wikiPath))
            return;

        $pluginFolder = $parent->getParent()->getPath('source') . '/authjoomlasso';
        if (Folder::copy($pluginFolder, $wikiPath . '/lib/plugins/authjoomlasso', '', true)) {
            Log::add('Succesfully updated wiki authjoomlasso plugin', Log::INFO, 'Update');
        } else {
            Log::add('Unable to copy wiki authjoomlasso plugin', Log::ERROR, 'Update');
        }
    }

    private function deleteWikiDoPlugin()
    {
        $wikiDoPluginPath = JPATH_ROOT . '/wiki/lib/plugins/do';
        if (!Folder::exists($wikiDoPluginPath))
            return;

        Folder::delete($wikiDoPluginPath);
        Log::add('Succesfully uninstalled wiki do plugin', Log::INFO, 'Update');
    }

    /**
     * Combining Salutation and Title into one single text field
     *
     * @since   6.8.0
     */
    private function combineSalutationAndTitle()
    {
        $db = Factory::getDbo();
        $dilerRegUsersTableColumns = $db->getTableColumns('#__dilerreg_users');
        // Skip if already updated
        if (!array_key_exists('title', $dilerRegUsersTableColumns))
            return;

        $query = $db->getQuery(true);
        $query->select('user_id, salutation, title')
            ->from('#__dilerreg_users');
        $dilerUsers = $db->setQuery($query)->loadObjectList();

        Factory::getLanguage()->load('com_diler', JPATH_ROOT . '/components/com_diler', null, true);

        foreach ($dilerUsers as $dilerUser) {
            $newTitle = Text::_($dilerUser->salutation);
            if ($dilerUser->title) {
                $newTitle .= ' ' . $dilerUser->title;
            }
            $query = $db->getQuery(true)->update('#__dilerreg_users')
                ->set('title = ' . $db->quote($newTitle))
                ->where('user_id = ' . $dilerUser->user_id);
            $db->setQuery($query)->execute();
        }
        $query = $db->getQuery(true);
        $query = "ALTER TABLE #__dilerreg_users DROP COLUMN `salutation`";
        $db->setQuery($query)->execute();

        $query = $db->getQuery(true);
        $query = "ALTER TABLE #__dilerreg_users CHANGE `title` `salutation` VARCHAR(225)";
        $db->setQuery($query)->execute();

        Log::add('Combining Salutation and Title into one field on update to 6.8.0.', Log::INFO, 'Update');
    }

    /**
     * Set null a component specific parameter.
     */
    private function removeComponentParam($componentName, $paramName)
    {
        $componentParams = ComponentHelper::getParams($componentName);
        $componentParams->set($paramName, '');
        $this->saveDilerParams($componentParams);

    }

    /**
     * Change permissions to not allowed.
     */
    private function removePermissions($permissionActions)
    {

        $configModel = new ConfigModelApplication;
        $permission = array(
            'component' => 'com_diler',
            'action' => '',
            'rule' => 1,
            'value' => 0,
            'title' => 'com_diler'
        );
        foreach ($permissionActions as $permissionAction) {
            $permission['action'] = $permissionAction;
            $configModel->storePermissions($permission);
        }
    }

    /**
     * remove old Diler package update source.
     */
    private function removeOldUpdateSource()
    {
        $packageName = 'Diler';
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__update_sites'))
            ->where($db->quoteName('name') . ' = ' . $db->quote($packageName));
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * copies phpseclib
     */
    public function copyPhpseclib()
    {



        $sourcePath = JPATH_SITE . '/components/com_diler/phpseclib/';
        $destinationPath = JPATH_SITE . '/libraries/vendor/phpseclib/';

        if (!Folder::exists($destinationPath)) {
            Folder::create(JPATH_SITE . '/libraries/vendor/phpseclib');
        }

        if (Folder::exists($destinationPath) && Folder::exists($sourcePath)) {
            $copy = Folder::copy($sourcePath, $destinationPath, null, true);
            if ($copy) {
                Log::add('Success! phpseclib copied.', Log::INFO, 'Update');
            } else {
                Log::add('Error! phpseclib not copied.', Log::INFO, 'Update');
            }
            if (Folder::exists($sourcePath))
                Folder::delete($sourcePath);
        } else {
            Log::add('Error! phpseclib not copied because of a folder issue', Log::INFO, 'Update');
        }
    }

    /**
     * copies dompdf (only if old folder still exists)
     * this should be executed only once, since the $sourcePath is deleted at the end.
     */
    public function copyDompdf()
    {



        $sourcePath = JPATH_SITE . '/components/com_diler/dompdf/';
        $destinationPath = JPATH_SITE . '/libraries/vendor/dompdf/';

        if (!Folder::exists($destinationPath)) {
            Folder::create(JPATH_SITE . '/libraries/vendor/dompdf');
        }

        if (Folder::exists($destinationPath) && Folder::exists($sourcePath)) {
            $copy = Folder::copy($sourcePath, $destinationPath, null, true);
            if ($copy) {
                Log::add('Success! dompdf copied.', Log::INFO, 'Update');
            } else {
                Log::add('Error! dompdf not copied.', Log::INFO, 'Update');
            }
            if (Folder::exists($sourcePath))
                Folder::delete($sourcePath);
        } else {
            Log::add('Error! dompdf not copied because of a folder issue', Log::INFO, 'Update');
        }
    }

    /**
     * copies html overrides to the Joomla admin templates
     */
    public function copyAdmintemplate()
    {



        if (VersionHelper::isJoomla4()) {
            $sourcePath = JPATH_ADMINISTRATOR . '/components/com_diler/admintemplate/';
            $destinationPath = JPATH_ADMINISTRATOR . '/templates/atum/';

            if (Folder::exists($destinationPath) && Folder::exists($sourcePath)) {
                $copy = Folder::copy($sourcePath, $destinationPath, null, true);
                if ($copy) {
                    Log::add('Success! admin template overrides copied.', Log::INFO, 'Update');
                } else {
                    Log::add('Error! admin template overrides not copied.', Log::INFO, 'Update');
                }
                if (Folder::exists($sourcePath))
                    Folder::delete($sourcePath);
            } else {
                echo "some wrong here at copyAdmintemplate";
            }
        } else {
            $sourcePath = JPATH_ADMINISTRATOR . '/components/com_diler/admintemplate/';
            $destinationPath1 = JPATH_ADMINISTRATOR . '/templates/hathor/';
            $destinationPath2 = JPATH_ADMINISTRATOR . '/templates/isis/';

            if (Folder::exists($destinationPath1) && Folder::exists($destinationPath2) && Folder::exists($sourcePath)) {
                $copy = Folder::copy($sourcePath, $destinationPath1, null, true);
                $copy = Folder::copy($sourcePath, $destinationPath2, null, true);
                if ($copy) {
                    Log::add('Success! admin template overrides copied.', Log::INFO, 'Update');
                } else {
                    Log::add('Error! admin template overrides not copied.', Log::INFO, 'Update');
                }
                if (Folder::exists($sourcePath))
                    Folder::delete($sourcePath);
            } else {
                echo "some wrong here at copyAdmintemplate";
            }
        }
    }

    /**
     * copy admin language files
     */
    public function copyAdminLanguages()
    {



        $sourcePath = JPATH_ADMINISTRATOR . '/components/com_diler/adminlanguage/';
        $destinationPath = JPATH_ADMINISTRATOR . '/language/';

        if (Folder::exists($destinationPath) && Folder::exists($sourcePath)) {
            $copy = Folder::copy($sourcePath, $destinationPath, null, true);
            if ($copy) {
                Log::add('Success! admin language files copied.', Log::INFO, 'Update');
            } else {
                Log::add('Error! admin language files not copied.', Log::INFO, 'Update');
            }
            if (Folder::exists($sourcePath))
                Folder::delete($sourcePath);
        } else {
            echo "some wrong here at copyAdminLanguages";
        }
    }

    /**
     * Removes files and folders that have been deleted since the prior version
     * Use the following command to get a list from git:
     * git diff --name-status working-3.3.4 working | grep "D"$'\t' > delete-files-0920c.txt
     */
    public function deleteRemovedFiles()
    {
        $this->addLogger();
        Log::add('Deleting old files and folders no longer in use.', Log::INFO, 'Update');

        // NOTE: Make sure they all start with "/"
        $folders = array(
            '/administrator/components/com_diler/images',
            '/administrator/components/com_diler/views/diler',
            '/administrator/components/com_diler/views/subjects/test',
            '/administrator/components/com_diler/views/extracurricular',
            '/administrator/components/com_diler/views/extracurriculars',
            '/components/com_diler/images',
            '/components/com_diler/views/student_talkie',
            '/components/com_diler/views/teacher_maintenance_extracurriculars',
            '/components/com_diler/views/teacher_maintenance_learning_groups',
            '/components/com_diler/views/teacher_maintenance_notifier_list',
            '/components/com_diler/views/teacher_maintenance_phases',
            '/components/com_diler/views/teacher_talkie',
            '/components/com_dilerreg/views/activation/tmpl',
            '/components/com_dilerreg/views/branchteacherregister',
            '/components/com_dilerreg/views/branchteacherassignstudent',
            '/templates/diler3/font',
            '/templates/diler3/css/bs4',
            '/templates/diler3/js/bs4',
            '/templates/diler3/html/mod_wuweather',
            '/templates/diler3/html/com_loginguard/captive',
            '/templates/diler3/html/mod_dpcalendar_upcoming',
            '/templates/diler3/html/mod_dpcalendar_upcoming-v6',
            '/libraries/vendor/dompdf/include',
            '/libraries/vendor/dompdf/www'
        );

        // NOTE: Make sure they all start with "/"
        $files = array(
            '/administrator/sample-school-import-profiles.zip',
            '/administrator/sample-user-import-profiles.zip',
            '/administrator/sample-user-import-regcodes.zip',
            '/administrator/components/com_diler/controllers/exercise.php',
            '/administrator/components/com_diler/controllers/exercises.php',
            '/administrator/components/com_diler/controllers/extracurricular.php',
            '/administrator/components/com_diler/controllers/extracurriculars.php',
            '/administrator/components/com_diler/controllers/input.php',
            '/administrator/components/com_diler/controllers/inputs.php',
            '/administrator/components/com_diler/controllers/mark.php',
            '/administrator/components/com_diler/controllers/marks.php',
            '/administrator/components/com_diler/controllers/point.php',
            '/administrator/components/com_diler/controllers/points.php',
            '/administrator/components/com_diler/controllers/task.php',
            '/administrator/components/com_diler/controllers/task.php',
            '/administrator/components/com_diler/controllers/tasks.php',
            '/administrator/components/com_diler/controllers/tasks.php',
            '/administrator/components/com_diler/controllers/test.php',
            '/administrator/components/com_diler/controllers/tests.php',
            '/administrator/components/com_diler/helpers/nimbustags.php',
            '/administrator/components/com_diler/language/de-DE/JSON.php',
            '/administrator/components/com_diler/models/exercise.php',
            '/administrator/components/com_diler/models/exercises.php',
            '/administrator/components/com_diler/models/extracurricular.php',
            '/administrator/components/com_diler/models/extracurriculars.php',
            '/administrator/components/com_diler/models/fields/nimbustag.php',
            '/administrator/components/com_diler/models/forms/diler.js',
            '/administrator/components/com_diler/models/forms/diler.xml',
            '/administrator/components/com_diler/models/forms/exercise.js',
            '/administrator/components/com_diler/models/forms/exercise.xml',
            '/administrator/components/com_diler/models/forms/extracurricular.js',
            '/administrator/components/com_diler/models/forms/extracurricular.xml',
            '/administrator/components/com_diler/models/forms/input.js',
            '/administrator/components/com_diler/models/forms/input.xml',
            '/administrator/components/com_diler/models/forms/mark.js',
            '/administrator/components/com_diler/models/forms/mark.xml',
            '/administrator/components/com_diler/models/forms/point.js',
            '/administrator/components/com_diler/models/forms/point.xml',
            '/administrator/components/com_diler/models/forms/task.js',
            '/administrator/components/com_diler/models/forms/task.js',
            '/administrator/components/com_diler/models/forms/task.xml',
            '/administrator/components/com_diler/models/forms/task.xml',
            '/administrator/components/com_diler/models/forms/test.js',
            '/administrator/components/com_diler/models/forms/test.xml',
            '/administrator/components/com_diler/models/input.php',
            '/administrator/components/com_diler/models/inputs.php',
            '/administrator/components/com_diler/models/mark.php',
            '/administrator/components/com_diler/models/marks.php',
            '/administrator/components/com_diler/models/point.php',
            '/administrator/components/com_diler/models/points.php',
            '/administrator/components/com_diler/models/rules/task.php',
            '/administrator/components/com_diler/models/rules/task.php',
            '/administrator/components/com_diler/models/task.php',
            '/administrator/components/com_diler/models/task.php',
            '/administrator/components/com_diler/models/tasks.php',
            '/administrator/components/com_diler/models/tasks.php',
            '/administrator/components/com_diler/models/test.php',
            '/administrator/components/com_diler/models/tests.php',
            '/administrator/components/com_diler/sql/updates/mysql/5.0.0.-diler4sql',
            '/administrator/components/com_diler/tables/exercise.php',
            '/administrator/components/com_diler/tables/extracurricular.php',
            '/administrator/components/com_diler/tables/input.php',
            '/administrator/components/com_diler/tables/mark.php',
            '/administrator/components/com_diler/tables/point.php',
            '/administrator/components/com_diler/tables/rules/mark.php',
            '/administrator/components/com_diler/tables/rules/point.php',
            '/administrator/components/com_diler/tables/rules/task.php',
            '/administrator/components/com_diler/tables/task.php',
            '/administrator/components/com_diler/tables/test.php',
            '/administrator/components/com_diler/tables/tests.php',
            '/administrator/components/com_diler/tables/observer/nimbustags.php',
            '/administrator/components/com_diler/views/levels/tmpl/edit.php',
            '/administrator/components/com_dilerreg/models/fields/family.php',
            '/components/com_diler/controllers/exercise.php',
            '/components/com_diler/controllers/input.php',
            '/components/com_diler/controllers/task.php',
            '/components/com_diler/controllers/task.php',
            '/components/com_diler/controllers/test.php',
            '/components/com_diler/helpers/encrypt.php',
            '/components/com_diler/images/diler/fitunterwegs-tagebuch-iccon-unterwegs.png',
            '/components/com_diler/layouts/group_subject_edit.php',
            '/components/com_diler/layouts/personaldata-old.php',
            '/components/com_diler/helpers/screenkeyboardkeys.php',
            '/components/com_diler/layouts/cloud_form.php',
            '/components/com_diler/layouts/dilertag.php',
            '/components/com_diler/layouts/nimbustag.php',
            '/components/com_diler/layouts/phase-assignment-modal.php',
            '/components/com_diler/models/exercise.php',
            '/components/com_diler/models/input.php',
            '/components/com_diler/models/listajax.php',
            '/components/com_diler/models/news.php',
            '/components/com_diler/models/phase.php',
            '/components/com_diler/models/rasterizer_test.php',
            '/components/com_diler/models/take_test.php',
            '/components/com_diler/models/take_test.php',
            '/components/com_diler/models/task.php',
            '/components/com_diler/models/task.php',
            '/components/com_diler/models/test.php',
            '/components/com_diler/models/test.php',
            '/components/com_diler/models/fields/dilercalendar.php',
            '/components/com_diler/models/fields/mediatag.php',
            '/components/com_diler/models/forms/group_subject.xml',
            '/components/com_diler/models/forms/subject_phase.xml',
            '/components/com_diler/views/parent_compchar/tmpl/default_exercise.php',
            '/components/com_diler/views/parent_compchar/tmpl/default_input.php',
            '/components/com_diler/views/parent_compchar/tmpl/default_test.php',
            '/components/com_diler/views/parent_student/tmpl/default_studentnotes.php',
            '/components/com_diler/views/parent_student/tmpl/default_texter.php',
            '/components/com_diler/views/student_compchar/tmpl/default_exercise.php',
            '/components/com_diler/views/student_compchar/tmpl/default_input.php',
            '/components/com_diler/views/student_compchar/tmpl/default_test.php',
            '/components/com_diler/views/student_desk/tmpl/default_studentnotes.php',
            '/components/com_diler/views/teacher_maintenance_compchar/tmpl/default_exercise.php',
            '/components/com_diler/views/teacher_maintenance_compchar/tmpl/default_input.php',
            '/components/com_diler/views/teacher_maintenance_compchar/tmpl/default_test.php',
            '/components/com_diler/views/teacher_student_compchar/tmpl/default_exercise.php',
            '/components/com_diler/views/teacher_student_compchar/tmpl/default_input.php',
            '/components/com_diler/views/teacher_student_compchar/tmpl/default_test.php',
            '/components/com_diler/views/teacher_student/tmpl/default_classregister.php',
            '/components/com_diler/views/teacher_student/tmpl/default_personaldataparent.php',
            '/components/com_diler/views/teacher_student/tmpl/default_reports.php',
            '/components/com_diler/views/teacher_student/tmpl/default_studentnotes.php',
            '/components/com_diler/views/teacher_student/tmpl/default_talkie.php',
            '/components/com_dilerajax/controllers/dilerajax_old.php',
            '/components/com_dilerreg/models/branchteacherassignstudent.php',
            '/components/com_dilerreg/models/branchteacherregister.php',
            '/images/diler/logos/monte-logo.png',
            '/images/diler/logos/asw-logo.png',
            '/media/com_diler/css/smoothDivScroll.css',
            '/media/com_diler/css/talkie_old.css',
            '/media/com_diler/images/fitunterwegs-tagebuch-iccon-unterwegs.png',
            '/media/com_diler/images/fitunterwegs-tagebuch-icon-unterwegs.png',
            '/media/com_diler/js/chat-talkie.js',
            '/media/com_diler/js/save-extracurricular-assignments.js',
            '/media/com_diler/js/save-group-assignments.js',
            '/media/com_diler/js/talkie-conference-ui.js',
            '/media/com_diler/js/talkie-online-user-list.js',
            '/media/com_diler/js/talkie-session.js',
            '/media/com_diler/js/talkie-ui.js',
            '/plugins/user/dilerreg/dilerreg_old.php',
            '/templates/diler3/html/mod_login/diler_logout.php',
            '/templates/diler3/html/com_diler/report_layouts/reports.css',
            '/templates/diler3/css/diler_5.4.0.css',
            '/templates/diler3/css/font-awesome3.min.css',
            '/templates/diler3/css/diler-pdf.css',
            '/templates/diler3/fonts/dilerlogo.eot',
            '/templates/diler3/fonts/dilerlogo.svg',
            '/templates/diler3/fonts/dilerlogo.ttf',
            '/templates/diler3/fonts/fontawesome-webfont.eot',
            '/templates/diler3/fonts/fontawesome-webfont.woff',
            '/templates/diler3/fonts/fontawesome-webfont.ttf',
            '/templates/diler3/fonts/fontawesome-webfont.svg',
            '/templates/diler3/images/calendar-mathe.png',
            '/templates/diler3/images/calendar-static.png',
            '/templates/diler3/images/calendar-teacher-static.png',
            '/templates/diler3/js/diler_script_old.js',
            '/libraries/vendor/dompdf/dompdf_config.inc.php',
            '/libraries/vendor/dompdf/dompdf_config.custom.inc.php',
            '/libraries/vendor/dompdf/dompdf.php',
            '/libraries/vendor/dompdf/diler-log.php',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansCondensed-Bold.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansCondensed-BoldOblique.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansCondensed-Oblique.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansCondensed.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerifCondensed-Bold.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerifCondensed-BoldItalic.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerifCondensed-Italic.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerifCondensed.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansCondensed-Bold.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansCondensed-BoldOblique.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansCondensed-Oblique.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansCondensed.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerifCondensed-Bold.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerifCondensed-BoldItalic.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerifCondensed-Italic.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerifCondensed.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSans.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSans-Bold.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSans-BoldOblique.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSans-ExtraLight.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansMono.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansMono-Bold.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansMono-BoldOblique.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansMono-Oblique.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSans-Oblique.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerif.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerif-Bold.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerif-BoldItalic.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerif-Italic.ttf',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSans.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSans-Bold.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSans-BoldOblique.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSans-ExtraLight.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansMono.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansMono-Bold.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansMono-BoldOblique.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansMono-BoldOblique.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSansMono-Oblique.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSans-Oblique.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerif.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerif-Bold.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerif-BoldItalic.ufm',
            '/libraries/vendor/dompdf/lib/fonts/DejaVuSerif-Italic.ufm',
            '/libraries/vendor/dompdf/lib/fonts/Courier.afm',
            '/libraries/vendor/dompdf/lib/fonts/Courier-Bold.afm',
            '/libraries/vendor/dompdf/lib/fonts/Courier-BoldOblique.afm',
            '/libraries/vendor/dompdf/lib/fonts/Courier-Oblique.afm',
            '/libraries/vendor/dompdf/lib/fonts/Helvetica.afm',
            '/libraries/vendor/dompdf/lib/fonts/Helvetica.afm.php',
            '/libraries/vendor/dompdf/lib/fonts/Helvetica-Bold.afm',
            '/libraries/vendor/dompdf/lib/fonts/Helvetica-Bold.afm.php',
            '/libraries/vendor/dompdf/lib/fonts/Helvetica-BoldOblique.afm',
            '/libraries/vendor/dompdf/lib/fonts/Helvetica-Oblique.afm',
            '/libraries/vendor/dompdf/lib/fonts/Helvetica-Oblique.afm.php',
            '/libraries/vendor/dompdf/lib/fonts/Times-Bold.afm',
            '/libraries/vendor/dompdf/lib/fonts/Times-BoldItalic.afm',
            '/libraries/vendor/dompdf/lib/fonts/Times-Italic.afm',
            '/libraries/vendor/dompdf/lib/fonts/Times-Roman.afm',
            '/libraries/vendor/dompdf/lib/fonts/Times-Roman.afm.php',
            '/administrator/components/com_diler/sql/updates/mysql/4.0.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/4.0.2.sql',
            '/administrator/components/com_diler/sql/updates/mysql/4.2.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/4.2.3.sql',
            '/administrator/components/com_diler/sql/updates/mysql/4.2.5.sql',
            '/administrator/components/com_diler/sql/updates/mysql/4.2.6.sql',
            '/administrator/components/com_diler/sql/updates/mysql/4.3.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/4.3.1.sql',
            '/administrator/components/com_diler/sql/updates/mysql/4.3.2.sql',
            '/administrator/components/com_diler/sql/updates/mysql/4.4.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.0.1.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.0.2.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.1.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.1.2.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.1.3.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.2.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.3.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.3.1.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.3.3.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.3.6.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.3.7.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.3.13.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.4.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.4.1.sql',
            '/administrator/components/com_diler/sql/updates/mysql/5.4.2.sql',
            '/administrator/components/com_diler/sql/updates/mysql/6.0.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/6.1.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/6.1.2.sql',
            '/administrator/components/com_diler/sql/updates/mysql/6.1.4.sql',
            '/administrator/components/com_diler/sql/updates/mysql/6.2.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/6.3.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/6.3.1.sql',
            '/administrator/components/com_diler/sql/updates/mysql/6.3.2.sql',
            '/administrator/components/com_diler/sql/updates/mysql/6.4.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/6.4.1.sql',
            '/administrator/components/com_diler/sql/updates/mysql/6.5.0.sql',
            '/administrator/components/com_diler/sql/updates/mysql/6.5.1.sql',
            '/administrator/components/com_diler/sql/updates/mysql/6.6.0.sql',
            '/administrator/components/com_dilerreg/sql/updates/mysql/0.0.1.sql',
            '/administrator/components/com_dilerreg/sql/updates/mysql/3.3.0.sql',
            '/administrator/components/com_dilerreg/sql/updates/mysql/4.0.0.sql',
            '/administrator/components/com_dilerreg/sql/updates/mysql/4.0.6.sql',
            '/administrator/components/com_dilerreg/sql/updates/mysql/4.1.0.sql'
        );



        $deleteFiles = 0;
        $errors = 0;
        foreach ($files as $file) {
            if (File::exists(JPATH_ROOT . $file)) {
                if (!File::delete(JPATH_ROOT . $file)) {
                    Log::add('ERROR: Failed to delete file ' . $file, Log::INFO, 'Update');
                    $errors++;
                } else {
                    Log::add('SUCCESS: Deleted file ' . $file, Log::INFO, 'Update');
                    $deleteFiles++;
                }
            }
        }



        $deletedFolders = 0;
        foreach ($folders as $folder) {
            if (Folder::exists(JPATH_ROOT . $folder)) {
                if (!Folder::delete(JPATH_ROOT . $folder)) {
                    Log::add('ERROR: Failed to delete folder ' . $folder, Log::INFO, 'Update');
                    $errors++;
                } else {
                    Log::add('SUCCESS: Deleted folder ' . $folder, Log::INFO, 'Update');
                    $deletedFolders++;
                }
            }
        }

        Log::add('Finished deleting old files no longer in use. ' . count($files) . ' files checked. ' . $deleteFiles . ' files deleted.', Log::INFO, 'Update');
        Log::add('Finished deleting old folders no longer in use. ' . count($folders) . ' folders checked. ' . $deletedFolders . ' folders deleted.', Log::INFO, 'Update');
        if ($errors) {
            Log::add('Warning! ' . $errors . ' errors encountered trying to delete files and folders.', Log::INFO, 'Update');
        }
    }

    /**
     * Remove the admin Diler User Management menu item if it exists
     */
    protected function deleteDilerregAdminMenu()
    {
        $db = Factory::getDbo();
        Table::addIncludePath(JPATH_BASE . '/libraries/legacy/table');
        Table::addIncludePath(JPATH_BASE . '/libraries/joomla/table/');
        $menuTable = new Menu(Factory::getDbo());
        $menuTable->load(array(
            'client_id' => 1,
            'title' => 'com_dilerreg'
        ));
        if (isset($menuTable->id)) {
            $result = $menuTable->delete($menuTable->id);
        }
        return $result;
    }

    /**
     * Remove PE-only files.
     */
    protected function convertToCE()
    {
        $files = array(
            '/media/com_diler/js/chat-talkie.js',
            '/media/com_diler/js/eventemitter.min.js',
            '/media/com_diler/js/palava.min.js',
            '/media/com_diler/js/talkie-conference-ui.js',
            '/media/com_diler/js/talkie-session.js',
            '/media/com_diler/js/talkie-ui.js',
            '/media/com_diler/js/student-talkie.js',
            '/media/com_diler/js/talkie.js',
            '/media/com_diler/js/talkie_util.js',
            '/media/com_diler/js/teacher-talkie.js',
            '/media/com_diler/js/state-machine.min.js',
            '/media/com_diler/js/talkie-online-user-list.js',
            '/media/com_diler/js/cloud.js',
            '/media/com_diler/js/nimbus.js'
        );




        foreach ($files as $file) {
            if (File::exists(JPATH_ROOT . $file) && !File::delete(JPATH_ROOT . $file)) {
                echo Text::sprintf('FILES_JOOMLA_ERROR_FILE_FOLDER', $file) . '<br />';
            }
        }
        if (Folder::exists(JPATH_ROOT . '/libraries/vendor/phpseclib')) {
            Folder::delete(JPATH_ROOT . '/libraries/vendor/phpseclib');
        }
        $this->setTranscoderServerLoginDetails('', '', '');
    }

    private function setTranscoderServerLoginDetails(string $host, string $username, string $password)
    {
        // Unset DiLer component options
        $params = ComponentHelper::getParams('com_diler');
        $params->set('ffmpeg_url', $host);
        $params->set('ffmpeg_username', $username);
        $params->set('ffmpeg_password', $password);

        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote($params->toString()))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_diler'));
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Fix the logout redirect bug in Joomla 3.6.0
     */
    protected function fix36LogoutBug()
    {
        $this->addLogger();
        Log::add('Checking for version 3.6.0 logout bug...', Log::INFO, 'Update');


        // Do only for Joomla 3.6.0
        if (version_compare(JVERSION, '3.6.0', 'eq')) {
            $sourcePath = JPATH_ROOT . '/components/com_diler/controllers/joomlauser.php';
            $destinationPath = JPATH_ROOT . '/components/com_users/controllers/user.php';

            if (File::exists($destinationPath) && File::exists($sourcePath)) {
                $delete = File::delete($destinationPath);
                $copy = File::copy($sourcePath, $destinationPath);
                if ($delete && $copy) {
                    Log::add('Success! File user.php copied to fix Joomla 3.6.0 bug.', Log::INFO, 'Update');
                } else {
                    Log::add('Error! Joomla 3.6.0 logout bug not fixed. Unable to copy file.', Log::INFO, 'Update');
                }
            }
        } else {
            Log::add('Not running Joomla version 3.6.0. No changes made.', Log::INFO, 'Update');
        }
    }

    protected function convertParentStudentData()
    {
        $this->addLogger();

        // First check that we don't already have parent relationship categories.
        // If we do, don't do anything.
        $db = Factory::getDbo();
        $query = $db->getQuery(true)->select('COUNT(*)')->from('#__categories')->where('extension = "com_diler.relationships"');
        $result = $db->setQuery($query)->loadResult();
        if ($result) {
            Log::add('Parent relationship categories already exist! No categories created.', Log::INFO, 'Update');
            return true;
        }

        // Add new categories for relationships
        $newCategories = array(
            'Eltern'
        );
        Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_categories/tables');
        BaseDatabaseModel::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_categories/models');

        $data = array(
            'parent_id' => '1',
            'level' => '1',
            'extension' => 'com_diler.relationships',
            'published' => 1,
            'access' => 1,
            'language' => '*',
            'associations' => array()
        );

        $db = Factory::getDbo();
        foreach ($newCategories as $title) {
            $data ['id'] = 0;
            $data ['title'] = $title;
            // Need to create new model instance for each insert.
            $result = BaseDatabaseModel::getInstance('Category', 'CategoriesModel')->save($data);
        }
        Log::add('Added categories for relationships', Log::INFO, 'Update');
    }

    /**
     * @param $data
     * @throws Exception
     * @version 5.4.1 => 6.11.0
     *
     */
    protected function createNewJoomlaUserGroup($data)
    {
        $groupModel = AdminModel::getInstance('Group', 'UsersModel');
        $result = $groupModel->save($data);
        $groupId = $groupModel->getState($groupModel->getName() . '.id');
        return $groupId;
    }

    /**
     * Update permissions for an asset
     * This method is using in allowViewAccessToGroupsAndTexterForDilerUsersGroups so its version is not 5.4.1 and can not be removed.
     *
     * @param int $assetId
     * @param \Joomla\CMS\Access\Rules  New permissions object to be merge
     * @return bool true if new permissions saved, false otherwise.
     * @version 5.4.1 => 6.10.3
     */
    protected function setPermissions($assetId, $newRules)
    {
        $assetTable = Joomla\CMS\Table\Asset::getInstance('Asset');
        $assetTable->id = $assetId;
        $assetTable->load();
        $rules = new Rules($assetTable->rules);
        $rules->merge($newRules);
        $assetTable->rules = (string)$rules;
        return $assetTable->store();
    }

    /**
     * Creates row in diler_schoolyear table only if no rows found in table.
     */
    protected function createSchoolYearRow()
    {
        $db = Factory::getDbo();
        $result = true;
        $query = $db->getQuery(true);
        $query->select('COUNT(*)')->from('#__diler_schoolyear');
        $rowsExist = $db->setQuery($query)->loadResult();
        if (!$rowsExist) {
            $params = ComponentHelper::getParams('com_diler');
            $start = $params->get('schoolyearStartDate', '0000-00-00');
            $end = $params->get('schoolyearEndDate', '0000-00-00');
            $name = substr($start, 0, 4);
            $query->clear()->insert('#__diler_schoolyear')
                ->set('id = 1')
                ->set('name = ' . $db->quote($name))
                ->set('start_date = ' . $db->quote($start))
                ->set('end_date = ' . $db->quote($end))
                ->set('published = 1')
                ->set('current = 1');
            $result = $db->setQuery($query)->execute();
        }
        return $result;
    }

    /**
     * Creates rows in diler_group_subject_section table, only if no rows already exist.
     */
    protected function updateV61GroupSubjectSectionMapTable()
    {
        $this->addLogger();
        Log::add('Starting updateV61GroupSubjectSectionMapTable.', Log::INFO, 'Update');

        $db = Factory::getDbo();
        $result = true;

        // Check that there are rows to be converted in old table and no rows in new table
        $query = $db->getQuery(true);
        $query->select('COUNT(*)')->from('#__diler_group_subject_section_map');
        $rowsExistNew = $db->setQuery($query)->loadResult();
        if ($rowsExistNew) {
            // Drop old table so we don't have any FK problems
            $db->dropTable('#__diler_group_subject_map');
            Log::add('New rows already exist. Nothing to do. updateV61GroupSubjectSectionMapTable complete.', Log::INFO, 'Update');
            return $result;
        }
        // Check that data exists to convert
        $query = $db->getQuery(true);
        $query->select('*')->from('#__diler_group_subject_map')->group('group_id');
        $oldRows = $db->setQuery($query)->loadObjectList();

        if (is_array($oldRows) && $oldRows) {
            $db->transactionStart();
            $query->clear()->insert('#__diler_group_subject_section_map')
                ->columns('group_id, subject_id, phase_id, section_id');
            foreach ($oldRows as $row) {
                $query->values($row->group_id . ',' . $row->subject_id . ',' . $row->phase_id . ',1');
            }
            $result = $db->setQuery($query)->execute();
            if ($result) {
                // Delete rows from old mapping table. They will cause problems with FK constraints. Need to drop the table in a future version.
                $query->clear()->delete('#__diler_group_subject_map');
                $result = $db->setQuery($query)->execute();
                if ($result) {
                    Log::add('Success! ' . count($oldRows) . ' rows inserted into diler_group_subject_section_map table.', Log::INFO, 'Update');
                    $db->transactionCommit();
                } else {
                    Log::add('Error trying to insert new rows into diler_group_subject_section_map table.', Log::ERROR, 'Update');
                    $db->transactionRollback();
                }
                $db->dropTable('#__diler_group_subject_map');
            }
        }
        return $result;
    }

    /**
     * Adds diler role group id to existing registration codes
     *
     * @return void true on success
     */
    protected function addGroupIdToRegCodes()
    {
        $roles = ['student' => ['student'], 'teacher' => ['teacher'], 'parent' => ['parent', 'mother', 'father']];
        $params = ComponentHelper::getParams('com_diler');
        foreach ($roles as $role => $roleArray) {
            $groupIdArray = $params->get($role . '_group_ids');
            $this->addGroupIdForRole($role, $roleArray, $groupIdArray);
        }
    }

    /**
     * Updates the dilerreg_registration_codes table to add a Group Id based on the role value
     *
     * @param string $role : student, parent, teacher
     * @param array $roleArray : array of values to test in db
     */
    protected function addGroupIdForRole($role, $roleArray, $groupIdArray)
    {
        if (!is_array($groupIdArray) || !$groupIdArray)
            return false;
        $oldestGroup = min($groupIdArray);
        $db = Factory::getDbo();
        $roleArrayQuoted = array_map(array($db, 'quote'), $roleArray);
        $query = $db->getQuery(true)->update('#__dilerreg_registration_codes')
            ->set('dilerrolegroup = ' . (int)$oldestGroup)
            ->where('role IN(' . implode(',', $roleArrayQuoted) . ')')
            ->where('dilerrolegroup IS NULL');
        return $db->setQuery($query)->execute();
    }

    /**
     * After update to 6.10.3 by default we do NOT allow veiw to Texter and Group.
     * For existing installations to avoid disappearing Texter and Groups as user had so far access to it,
     * we will set 'allow' for 'DiLer user groups'
     */
    private function allowViewAccessToGroupsAndTexterForDilerUsersGroups()
    {
        $dilerOptions = ComponentHelper::getParams('com_diler');
        $lgId = $dilerOptions->get('learning_group_parent_id');
        BaseDatabaseModel::addIncludePath(JPATH_ROOT . '/administrator/components/com_users/models/');
        $lgGroup = BaseDatabaseModel::getInstance('Group', 'UsersModel')->getItem($lgId);
        // 'parent_id' is ID of 'DiLer user groups' - we do it this way to be sure we match it as some schools maybe changed
        // name of 'Diler user groups' to something else
        $ruleArray['texter.view'] = [(string)$lgGroup->parent_id => 1];
        $ruleArray['groups.view'] = [(string)$lgGroup->parent_id => 1];

        $assetId = $this->getComDilerAssetId();

        // Set up new permissions
        $newRules = new Rules(json_encode($ruleArray));
        $permissionsSet = $this->setPermissions($assetId, $newRules);
        if ($permissionsSet) {
            Log::add('Success! Groups and Texter view permissions updated for version 6.10.3.', Log::INFO, 'Update');
            return;
        }

        Log::add('Error! Groups and Texter veiw permission not updated for version 6.10.3.', Log::INFO, 'Update');
        Factory::getApplication()->enqueueMessage(DText::_('ERROR_CAN_NOT_UPDATE_TEXTER_AND_GROUPS_VIEW_PERMISSION'), 'error');
    }

    private function getComDilerAssetId()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id');
        $query->from('#__assets');
        $query->where('name = "com_diler"');
        return $db->setQuery($query)->loadResult();
    }

    protected function addLogger()
    {
        $options ['format'] = '{DATE}\t{TIME}\t{LEVEL}\t{CODE}\t{MESSAGE}';
        $options ['text_file'] = 'diler-log.php';
        Log::addLogger($options, Log::INFO, array(
            'Update',
            'diler',
            'error'
        ));
    }

    /**
     * Deletes rows with duplicate key values
     * @param int $groupId
     * @param array $subjectPhase
     */
    protected function marksHistoryRemoveDuplicates($groupId, $subjectPhase)
    {
        // Delete values where phase != 0
        $db = Factory::getDbo();
        $query = $db->getQuery(true)->select('CONCAT(student_id, ":", group_id, ":", subject_id, ":", schoolyear_id, ":", marks_period_id) AS key_values')
            ->from('#__diler_marks_history')
            ->where('group_id = ' . (int)$groupId)
            ->where('phase_id = 0');
        $keyValues = $db->setQuery($query)->loadColumn();
        $keyValues = array_map([$db, 'quote'], $keyValues);
        if (is_array($keyValues) && $keyValues) {
            $query->clear()->delete('#__diler_marks_history')
                ->where('phase_id != 0')
                ->where('CONCAT(student_id, ":", group_id, ":", subject_id, ":", schoolyear_id, ":", marks_period_id) IN( ' . implode(',', $keyValues) . ')');
            $deleteRows = $db->setQuery($query)->execute();
        }
        // Rerun query
        $query->clear()->update('#__diler_marks_history')
            ->set('phase_id = ' . (int)$subjectPhase['phase'])
            ->where('group_id = ' . (int)$groupId)
            ->where('phase_id = 0');
        $result = $db->setQuery($query)->execute();
    }

    /**
     * @since 6.11.5
     */
    private function getDilerVersion()
    {
        $xml = simplexml_load_file(JPATH_ROOT . '/administrator/manifests/packages/pkg_diler.xml');
        return (string)$xml->version;
    }

    /**
     * @since 6.11.5
     */
    private function createDefaultBuildingHouseA()
    {
        $previousSavedRooms = $this->getAllSavedRooms();
        $defaultLocationId = $this->insertDefaultLocationRecord($previousSavedRooms);
        $this->changeGroupsRoom($defaultLocationId);
        $this->changeSavedGroupSchedules($defaultLocationId);
    }

    /**
     * @since 6.11.5
     */
    private function getAllSavedRooms()
    {
	    if (!ComponentHelper::isEnabled('com_dpcalendar'))
		    return null;

        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('dpl.title, dpl.id')
            ->from('#__dpcalendar_locations AS dpl');
        $rows = $db->setQuery($query)->loadObjectList();
        $rooms = array();
        foreach ($rows as $key => $row) {
            $rooms['rooms' . $key] = array(
                'id' => $row->id,
                'title' => $row->title,
                'description' => ''
            );
        }

        return (object)$rooms;
    }

    /**
     * @since 6.11.5
     */
    private function insertDefaultLocationRecord($rooms)
    {
	    if (!ComponentHelper::isEnabled('com_dpcalendar'))
		    return null;

        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*')
            ->from('#__dpcalendar_locations')
            ->where('title = "House A"');
        $room = $db->setQuery($query)->loadObjectList();
        if ($room) {
            return $room[0]->id;
        }
        $insertQuery = $db->getQuery(true);
        $insertQuery->insert('#__dpcalendar_locations')
            ->set('title = "House A"')
            ->set('state = "1"')
            ->set('rooms=' . $db->quote(json_encode((array)$rooms)));
        $db->setQuery($insertQuery)->execute();
        return $db->insertid();
    }

    /**
     * @since 6.11.5
     */
    private function changeGroupsRoom($defaultLocationId)
    {
        $previousGroupsWithRooms = $this->getAllGroupsWithRoom();
        foreach ($previousGroupsWithRooms as $group) {
            $this->manipulateGroupRoomToDefaultRoom($group->id, $defaultLocationId, $group->room);
        }
    }

    /**
     * @since 6.11.5
     */
    private function getAllGroupsWithRoom()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*')
            ->from('#__diler_group')
            ->where('room IS NOT NULL');
        return $db->setQuery($query)->loadObjectList();
    }

    /**
     * @since 6.11.5
     */
    private function manipulateGroupRoomToDefaultRoom($groupId, $defaultLocationId, $locationId)
    {
        $roomTitle = $this->getRoomTitleById($locationId);
        if (!$roomTitle)
            return;
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__diler_group')
            ->where('id=' . $db->quote($groupId))
            ->where('room_sub_location IS NULL')
            ->set('room=' . $db->quote($defaultLocationId))
            ->set('room_sub_location=' . $db->quote($roomTitle));
        $db->setQuery($query)->execute();
    }

    /**
     * @since 6.11.5
     */
    private function getRoomTitleById($locationId)
    {
	    if (!ComponentHelper::isEnabled('com_dpcalendar'))
		    return null;

        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('title')
            ->from('#__dpcalendar_locations')
            ->where('id = ' . $db->quote($locationId));
        return $db->setQuery($query)->loadResult();
    }

    /**
     * @since 6.11.5
     */
    private function changeSavedGroupSchedules($defaultLocationId)
    {
        $previousGroupSchedules = $this->getAllGroupSchedules();
        foreach ($previousGroupSchedules as $groupSchedule) {
            $this->manipulateGroupSchedulesToDefaultRoom($defaultLocationId, $groupSchedule->location_id);
        }
    }

    /**
     * @since 6.11.5
     */
    private function manipulateGroupSchedulesToDefaultRoom($defaultLocationId, $locationId)
    {
        $roomTitle = $this->getRoomTitleById($locationId);
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__diler_group_schedule')
            ->where('location_id=' . $db->quote($locationId))
            ->where('room IS NULL')
            ->set('location_id=' . $db->quote($defaultLocationId))
            ->set('room=' . $db->quote($roomTitle));
        $db->setQuery($query)->execute();
    }

    /**
     * @since 6.11.5
     */
    private function getAllGroupSchedules()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*')
            ->from('#__diler_group_schedule');
        return $db->setQuery($query)->loadObjectList();
    }


    private function saveDilerParams($params)
    {
        $componentId = ComponentHelper::getComponent('com_diler')->id;
        $extensionTable = Table::getInstance('extension');
        $extensionTable->load($componentId);
        $extensionTable->bind(array('params' => $params->toString()));
        $extensionTable->check() && $extensionTable->store();
    }

    private function cleanDilerTeacherNotesNoteField()
    {
        $db = Factory::getDbo();
        $inputFilter = InputFilter::getInstance([], [], 1, 1);

        $query = $db->getQuery(true);
        $query->select('*');
        $query->from('#__diler_teacher_mynotes');
        $query->where('temp_clean = 0');
        $notes = $db->setQuery($query)->loadObjectList();

        foreach ($notes as $note) {
            $updateQuery = $db->getQuery(true);
            $updateQuery->update('#__diler_teacher_mynotes');
            $updateQuery->set('note = ' . $db->quote($this->getSafeHtml($inputFilter, $note->note)));
            $updateQuery->set("temp_clean = '1'");
            $updateQuery->where('student_id = ' . $note->student_id);
            $updateQuery->where('teacher_id = ' . $note->teacher_id);
            $db->setQuery($updateQuery)->execute();
        }
    }

    private function cleanDilerregUsersTableNoteFields()
    {
        $db = Factory::getDbo();
        $inputFilter = InputFilter::getInstance([], [], 1, 1);

        $query = $db->getQuery(true);
        $query->select('user_id, contact_note, notebyteacherptmprivate,student_alert_note_teacher,student_alert_note');
        $query->from('#__dilerreg_users');
        $query->where("temp_clean = 0");
        $query->where("(contact_note !='' OR notebyteacherptmprivate !='' OR student_alert_note_teacher !='' OR student_alert_note !='')");

        $users = $db->setQuery($query)->loadObjectList();

        foreach ($users as $user) {
            $user->contact_note = $this->getSafeHtml($inputFilter, $user->contact_note);
            $user->notebyteacherptmprivate = $this->getSafeHtml($inputFilter, $user->notebyteacherptmprivate);
            $user->student_alert_note_teacher = $this->getSafeHtml($inputFilter, $user->student_alert_note_teacher);
            $user->student_alert_note = $this->getSafeHtml($inputFilter, $user->student_alert_note);
            $user->temp_clean = 1;
            $db->updateObject('#__dilerreg_users', $user, 'user_id');
        }
    }

    private function getSafeHtml(InputFilter $inputFilter, $content)
    {
        return $inputFilter->clean($content, 'html');
    }

    private function assignCloudFilesCreatedDateFromDisk()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id, path');
        $query->from('#__diler_cloud');
        $query->where('created IS NULL');
        $query->orWhere('created = "0000-00-00 00:00:00"');

        $files = $db->setQuery($query)->loadAssocList();
        $fileBasePath = DilerHelperUser::getRootFileFolder();
        foreach ($files as $file) {

            $filePath = $fileBasePath . $file['path'];
            if (file_exists($filePath)) {
                $createdDateTime = Factory::getDate(filemtime($filePath));
                $query->clear()
                    ->update('#__diler_cloud')
                    ->set('created = ' . $db->quote($createdDateTime->toSql()))
                    ->where('id = ' . $file['id']);
                $db->setQuery($query)->execute();
            }
        }

    }

    private function doWeNeedToPopulateDobFieldFromDobObsolete()
    {
        $db = Factory::getDbo();
        return array_key_exists('dob_not_populated', $db->getTableColumns('#__dilerreg_users'));
    }

    private function fixDobFieldInUsersTable()
    {
        foreach ($this->getUserDobs() as $id => $dob) {
            try {
                $date = new DateTime($dob);
                $this->saveUserDobDate(Factory::getDate($date->format('Y-m-d'))->toSql(), $id);
            } catch (\Exception $exception) {
                Log::add('Invalid "dob" date in #__dilerreg_users for user with id: ' . $id . '. Used date: ' . $dob, Log::ERROR, 'Update');
                $this->saveUserDobDate(null, $id);
            }
        }
    }

    private function getUserDobs()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('dob_obsolete, user_id');
        $query->from('#__dilerreg_users');
        $query->where("dob_obsolete !=''");

        return $db->setQuery($query)->loadAssocList('user_id', 'dob_obsolete');
    }

    private function saveUserDobDate($date, $userId)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__dilerreg_users');
        $query->set('dob = ' . $db->quote($date));
        $query->where('user_id = ' . $db->quote($userId));
        $db->setQuery($query)->execute();
    }

    private function removeDobNotPopulatedField()
    {
        Factory::getDbo()->setQuery('ALTER TABLE `#__dilerreg_users` DROP COLUMN `dob_not_populated`')->execute();
    }

    public function populatePepMethodsTable(): void
    {
        $methods = PepMethod::methodsSortedByValue();
        $methodsForInsert = array();
        foreach ($methods as $method) {
            if (!$this->doesPepMethodExist($method->value()))
                $methodsForInsert[] = $method;
        }

        if ($methodsForInsert) {
            $language = new Joomla\CMS\Language\Language('de-DE');
            $language->load('com_diler', JPATH_ROOT . '/components/com_diler');
            $db = Factory::getDbo();
            foreach ($methodsForInsert as $method) {
                $query = $db->getQuery();
                $query->insert('#__diler_pep_methods');
                $values = array(
                    $method->value(),
                    $db->quote($language->_('COM_DILER_' . $method->plainTitle())),
                    'NULL',
                    0,
                    $db->quote(Factory::getDate()->toSql()),
                    Factory::getUser()->id,
                    'NULL',
                    0
                );
                $query->values(implode(',', $values));
            }

            $db->setQuery($query)->execute();
        }
    }

    private function doesPepMethodExist(int $id)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id');
        $query->from('#__diler_pep_methods');
        $query->where('id = ' . $id);
        return $db->setQuery($query)->loadResult();
    }

    private function updatePepAssessmentsWithPepDevelopmentAreaId($developmentAreaId)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__diler_pep_assessments');
        $query->where('development_area_id = 0');
        $query->set('development_area_id =' . $developmentAreaId);
        $db->setQuery($query)->execute();
    }

    private function getPepDevelopmentAreaDefaultId()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id');
        $query->from('#__diler_pep_development_area');
        $query->where('title = "General Pep Development Area"');
        $id = $db->setQuery($query)->loadResult();
        if ($id)
            return $id;

        $updateQuery = $db->getQuery(true);
        $updateQuery->insert('#__diler_pep_development_area');
        $updateQuery->set('title = "General Pep Development Area"');
        $updateQuery->set('created_by=' . Factory::getUser()->id);
        $db->setQuery($updateQuery)->execute();
        return $db->insertid();
    }

    private function removeStudentIdAndGroupIdFromPepTable()
    {
        $studentColumn = 'student_id';
        $subjectGroupCoumn = 'subject_group_id';
        $studentIdForeignKeyName = $this->getPepForeignKeyName($studentColumn);
        $subjectGroupIdForeignKeyName = $this->getPepForeignKeyName($subjectGroupCoumn);
        if ($studentIdForeignKeyName) {
            $this->dropForeignKeyFromPep($studentIdForeignKeyName);
            $this->dropPepColumn($studentColumn);
        }

        if ($subjectGroupIdForeignKeyName) {
            $this->dropForeignKeyFromPep($subjectGroupIdForeignKeyName);
            $this->dropPepColumn($subjectGroupCoumn);
        }
    }

    private function getPepForeignKeyName($columnName)
    {
        $db = Factory::getDbo();
        $studentIdForeignNameQuery = "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
          WHERE TABLE_NAME = '" . $db->getPrefix() . "diler_pep'
          AND CONSTRAINT_SCHEMA = " . $db->quote(Factory::getConfig()->get('db')) . "
		  AND COLUMN_NAME = " . $db->quote($columnName) . " LIMIT 1";
        return $db->setQuery($studentIdForeignNameQuery)->loadResult();
    }

    private function dropForeignKeyFromPep($keyName)
    {
        $db = Factory::getDbo();
        $dropForeignKey = "ALTER TABLE " . $db->quoteName($db->getPrefix() . "diler_pep") .
            "DROP FOREIGN KEY " . $db->quoteName($keyName);
        $db->setQuery($dropForeignKey)->execute();
    }

    private function dropPepColumn($columnName)
    {
        $db = Factory::getDbo();
        $dropColumn = "ALTER TABLE " . $db->quoteName($db->getPrefix() . "diler_pep") .
            "DROP COLUMN " . $db->quoteName($columnName);
        $db->setQuery($dropColumn)->execute();
    }

    private function deleteDilerUsersThatDontExistInJoomlaUsers(): void
    {
        Factory::getDbo()->setQuery('DELETE dilerreg
    FROM `#__dilerreg_users` AS dilerreg
    LEFT JOIN `#__users` AS jusers ON jusers.id = dilerreg.user_id
    WHERE jusers.id IS NULL')->execute();
    }

    private function populateSchoolPrincipalUserId()
    {
        $schools = $this->getBaseSchools();
        $missingPrinciplesTmpFile = Factory::getConfig()->get('tmp_path') . '/diler/schools_with_missing_principal_user_ids.txt';
        $isThereMissingPrinciples = false;
        foreach ($schools as $school) {
            if ($school->user_id) {
                $this->savePrincipleUserIdForBaseSchool($school->user_id, $school->id);
            } else {
                $isThereMissingPrinciples = true;
                File::append($missingPrinciplesTmpFile, $school->name . "\n");
            }
        }

        if ($isThereMissingPrinciples)
            Factory::getApplication()->enqueueMessage(DText::sprintf('THERE_IS_SCHOOLS_WITH_MISSING_PRINCIPLES_CHECK_LOG_FILE', $missingPrinciplesTmpFile));
    }

    private function getBaseSchools()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('s.name, du.user_id, s.principal_name, s.id');
        $query->from('#__diler_school AS s');
        $query->leftJoin('#__dilerreg_users AS du ON s.principal_name = CONCAT(du.forename, " " , du.surname)');
        $query->where('s.base_school = 1');
        return $db->setQuery($query)->loadObjectList();
    }

    private function savePrincipleUserIdForBaseSchool($principleUserId, $schoolId)
    {
        $db = Factory::getDbo();
        $updateQuery = $db->getQuery(true);
        $updateQuery->update('#__diler_school');
        $updateQuery->set('principal_user_id = ' . $updateQuery->quote($principleUserId));
        $updateQuery->where('id = ' . $updateQuery->quote($schoolId));
        $db->setQuery($updateQuery)->execute();
    }

    private function updateCountriesAndStates(): void
    {
        $existingCountryIsos = $this->getExistingCountryIsos();
        $countries = $this->getCountriesForImport();

        $stateValues = array();
        foreach ($countries as $country) {
            if (in_array($country->code2, $existingCountryIsos))
                continue;

            $countryId = $this->insertCountryAndGetInsertedId($country->code2, $country->name);

            foreach ($country->states as $state)
                $stateValues[] = $this->getStateValuesForInsert($state, $countryId, $country->code2);
        }
        if ($stateValues) {
            try {
                $this->insertStates($stateValues);
                $this->deleteCountriesJsonFile();
            } catch (Exception $exception) {
                Factory::getApplication()->enqueueMessage($exception->getMessage(), 'error');
            }
        }

    }

    private function getExistingCountryIsos()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('iso2');
        $query->from('#__diler_country');
        return $db->setQuery($query)->loadColumn();
    }

    private function insertCountryAndGetInsertedId($iso, $name)
    {
        $db = Factory::getDbo();
        $queryInsertCountries = $db->getQuery(true);
        $queryInsertCountries->insert('#__diler_country');
        $queryInsertCountries->set('iso2 =' . $queryInsertCountries->quote($iso));
        $queryInsertCountries->set('name =' . $queryInsertCountries->quote($name));
        $queryInsertCountries->set('published = 1');

        $db->setQuery($queryInsertCountries)->execute();

        return $db->insertid();
    }

    private function getCountriesForImport()
    {
        $importString = file_get_contents(JPATH_ROOT . '/media/com_diler/countries.json', true);
        return json_decode($importString);
    }

    private function getStateValuesForInsert($state, $countryId, $countryIso)
    {
        $db = Factory::getDbo();

        return
            $db->quote($countryIso . '_' . $state->code) . ',' .
            $db->quote($state->name) . ',' .
            $db->quote($countryId) . ',' .
            $db->quote($countryIso);
    }

    private function insertStates($stateValues)
    {
        $db = Factory::getDbo();
        $insert = $db->getQuery(true);
        $insert->insert('#__diler_state');
        $insert->columns(array('state_iso', 'name', 'country_id', 'country_iso2'));
        $insert->values($stateValues);
        $db->setQuery($insert)->execute();
    }

    private function deleteCountriesJsonFile()
    {
        $pathToCountriesFile = JPATH_ROOT . '/media/com_diler/countries.json';
        if (File::exists($pathToCountriesFile))
            File::delete($pathToCountriesFile);
    }


    private function fixStartAndEndTimeIn($table)
    {
        foreach ($this->getStartAndEndTime($table) as $classSchedule) {
            try {
                $startTime = new DateTime($classSchedule->start_time_obsolete);
                $endTime = new DateTime($classSchedule->end_time_obsolete);
                $this->saveStartAndEndTime(
                    $table,
                    Factory::getDate($startTime->format('H:i'))->toSql(),
                    Factory::getDate($endTime->format('H:i'))->toSql(),
                    $classSchedule->id
                );
            } catch (\Exception $exception) {
                Log::add('Something went wrong while updating the' . $table . ' for id: ' . $classSchedule->id, Log::ERROR, 'Update');
            }
        }
    }

    private function getStartAndEndTime($table)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id, start_time_obsolete, end_time_obsolete');
        $query->from($table);
        return $db->setQuery($query)->loadObjectList();
    }

    private function saveStartAndEndTime($table, $startTime, $endTime, $id)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update($table);
        $query->set('start_time = ' . $db->quote($startTime));
        $query->set('end_time = ' . $db->quote($endTime));
        $query->where('id = ' . $db->quote($id));
        $db->setQuery($query)->execute();
    }

    private function ifThereIsMissingDataIGlobalConfigurationShowWarningMessages()
    {
        $app = Factory::getApplication();
        $params = DilerParams::init();

        if (!$params->getSchoolPostalCode())
            $app->enqueueMessage(Text::_('COM_DILER_SCHOOL_POSTAL_CODE_MISSING_IN_GLOBAL_CONFIG'), 'error');

        if (!$params->getSchoolCountry())
            $app->enqueueMessage(Text::_('COM_DILER_SCHOOL_COUNTRY_MISSING_IN_GLOBAL_CONFIG'), 'error');

        if (!$params->getSchoolState())
            $app->enqueueMessage(Text::_('COM_DILER_SCHOOL_STATE_MISSING_IN_GLOBAL_CONFIG'), 'error');

    }

    private function populateNewTableForRegionTeachersAtTheTimeOfEnrollment()
    {
        $fromOldTable = $this->getRegionTeachersAtTimeOfStudentEnrollmentFromOldTable();
        $this->insertRegionTeacherAtTimeOfEnrollmentToNewTable($fromOldTable);
        $fromSchoolHistory = $this->getRegionTeachersForAtTimeOfEnrollmentFromSchoolHistory();
        $this->insertRegionTeacherAtTimeOfEnrollmentToNewTable($fromSchoolHistory);
    }

    private function getRegionTeachersAtTimeOfStudentEnrollmentFromOldTable()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('history.user_id');
        $query->select('region_teachers.teacher_id');
        $query->select('history.id as history_id');
        $query->select('ju.name');
        $query->select('school.base_school');
        $query->from('#__diler_region_teachers_at_time_of_student_enrolment AS region_teachers');
        $query->innerJoin('#__diler_user_school_history AS history ON region_teachers.school_history_id = history.id');
        $query->innerJoin('#__diler_school AS school ON school.id = history.school_id AND school.base_school = 1');
        $query->innerJoin('#__users AS ju ON history.user_id = ju.id');
        $query->leftJoin('#__diler_student_region_teacher_at_time_of_enrollment as student_region_teachers on student_region_teachers.student_id = history.user_id');
        $query->where('student_region_teachers.student_id IS NULL');
        $query->groupBy('region_teachers.teacher_id, history.user_id');
        $query->order('history.id');

        $results = $db->setQuery($query)->loadAssocList();
        return $this->prepareRegionTeachersForInsertToNewTable($results);
    }

    private function getRegionTeachersForAtTimeOfEnrollmentFromSchoolHistory()
    {

        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('history.id as history_id');
        $query->select('teacher_user.id as teacher_id');
        $query->select('student_user.id as user_id');
        $query->from('#__diler_user_school_history AS history');
        $query->innerJoin('#__diler_school AS school ON history.school_id = school.id');
        $query->innerJoin('#__diler_region as region ON region.postal_code = school.postal_code AND school.country_iso2 = region.country_iso2');
        $query->innerJoin('#__diler_region_user_map AS region_users ON region_users.region_id = region.id');
        $query->innerJoin('#__users AS teacher_user ON teacher_user.id = region_users.user_id');
        $query->innerJoin('#__users AS student_user ON student_user.id = history.user_id');
        $query->leftJoin('#__diler_student_region_teacher_at_time_of_enrollment AS region_teacher ON region_teacher.student_id = history.user_id');
        $query->where('school.base_school = 1');
        $query->where('region_teacher.student_id IS NULL');
        $results = $db->setQuery($query)->loadAssocList();
        return $this->prepareRegionTeachersForInsertToNewTable($results);
    }

    private function prepareRegionTeachersForInsertToNewTable($resultsFromDb)
    {
        if (!$resultsFromDb)
            return array();

        $teachersByStudentIdAndHistoryId = array();
        foreach ($resultsFromDb as $result)
            $teachersByStudentIdAndHistoryId[$result['user_id']][$result['history_id']][] = $result['teacher_id'];

        $forInsert = array();
        foreach ($teachersByStudentIdAndHistoryId as $key => $item) {
            $teachers = array_values($item)[0];
            foreach ($teachers as $teacher)
                $forInsert[] = $key . ',' . $teacher;
        }

        return $forInsert;
    }

    private function insertRegionTeacherAtTimeOfEnrollmentToNewTable($forInsert)
    {
        if ($forInsert) {
            $db = Factory::getDbo();
            $insert = $db->getQuery(true);
            $insert->insert('#__diler_student_region_teacher_at_time_of_enrollment');
            $insert->columns(array('student_id', 'teacher_id'));
            $insert->values($forInsert);
            $db->setQuery($insert)->execute();
        }
    }

    private function setSubjectPublishUpAndPublishDownToZeroDate()
    {
        $db = Factory::getDbo();
        $zeroDateTime = '0000-00-00 00:00:00';
        $query = $db->getQuery(true);
        $query->update('#__diler_subject');
        $query->set('publish_up = ' . $db->quote($zeroDateTime));
        $query->set('publish_down = ' . $db->quote($zeroDateTime));

        return $db->setQuery($query)->execute();
    }

    private function fixSchoolHistoryEndAndStartDays()
    {
        $histories = $this->getSchoolHistories();
        $timezone = new DateTimeZone(Factory::getConfig()->get('offset'));

        $newDates = array();
        foreach ($histories as $history) {

            $enrolStartHour = Factory::getDate($history['enroll_start'])->hour;
            if ($enrolStartHour != "00") {
                $offsetInSec = $timezone->getOffset(Factory::getDate($history['enroll_start']));
                $history['enroll_start'] = Factory::getDate($history['enroll_start'] . ' +' . $offsetInSec . 'seconds')->toSql();
                $history['enroll_end'] = Factory::getDate($history['enroll_end'] . ' +' . $offsetInSec . 'seconds')->toSql();
                $newDates[] = $history;
            }
        }

        $this->updateSchoolHistoryDates($newDates);
    }

    private function getSchoolHistories()
    {
        $nullDate = Factory::getDbo()->getNullDate();
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id, enroll_start, enroll_end');
        $query->from('#__diler_user_school_history');
        $query->where('enroll_start !=' . $db->quote($nullDate), 'OR');
        $query->where('enroll_end !=' . $db->quote($nullDate), 'OR');

        return $db->setQuery($query)->loadAssocList();
    }

    private function updateSchoolHistoryDates($schoolHistories)
    {
        $db = Factory::getDbo();
        foreach ($schoolHistories as $history) {
            $query = $db->getQuery(true);
            $query->update('#__diler_user_school_history');
            $query->set('enroll_start = ' . $db->quote($history['enroll_start']));
            $query->set('enroll_end = ' . $db->quote($history['enroll_end']));
            $query->where('id = ' . $db->quote($history['id']));
            $db->setQuery($query)->execute();
        }
    }

    private function getVersionIdByElement()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('sch.version_id');
        $query->from('#__extensions AS ex');
        $query->innerJoin('#__schemas AS sch ON ex.extension_id = sch.extension_id');
        $query->where('element = "com_diler"');
        return $db->setQuery($query)->loadResult();
    }

    private function deleteSqlFilesOlderThanCurrentVersion()
    {
        $versionId = $this->getVersionIdByElement();
        $arrayOfFilesFromFolder = Folder::files(JPATH_ADMINISTRATOR . '/components/com_diler/sql/updates/mysql\/');
        foreach ($arrayOfFilesFromFolder as $file)
        {
            if (!str_contains($file, 'sql'))
                continue;
            $filesWithoutExtension = str_replace('.sql', '', $file);
            if (version_compare($filesWithoutExtension, $versionId, '<='))
                File::delete(JPATH_ADMINISTRATOR . '/components/com_diler/sql/updates/mysql\/' . $file);
        }
    }

    private function setDefaultVeluForCreatedInSchoolHistoryTable()
    {
        Factory::getDbo()->setQuery('ALTER TABLE `#__diler_studentrecord_history` CHANGE `created` `created` DATETIME DEFAULT CURRENT_TIMESTAMP')->execute();
    }

    private function deletePepMethodsFromLibraries(): void
    {
        $pepMethodsFolder = JPATH_ROOT . '/components/com_diler/libraries/DiLer/Core/Pep/Methods';
        $pepMethodFile = JPATH_ROOT . '/components/com_diler/libraries/DiLer/Core/Pep/PepMethod.php';

        if (Folder::exists($pepMethodsFolder))
            Folder::delete($pepMethodsFolder);

        if (File::exists($pepMethodFile))
            File::delete($pepMethodFile);
    }


    private function addColumnStandardClassScheduleIfNotExist()
    {
        $db = Factory::getDbo();
        $dilerUserTable = $db->getTableColumns('#__dilerreg_users');
        if (!array_key_exists('standard_class_schedule', $dilerUserTable))
            Factory::getDbo()->setQuery('ALTER TABLE `#__dilerreg_users` ADD COLUMN `standard_class_schedule` INT UNSIGNED DEFAULT 0 NULL AFTER `role`')->execute();
    }

	private function deleteIsisTemplate()
	{
		$isisTemplateFolder = JPATH_ADMINISTRATOR . '/templates/isis';
		if (Folder::exists($isisTemplateFolder))
			Folder::delete($isisTemplateFolder);
	}

	private function changeWikiMenuLink()
	{
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		$query->update('#__menu');
		$query->set('link = ' .  $db->quote('/wiki'));
		$query->where('link =' . $db->quote('index.php?/wiki'));
		return $db->setQuery($query)->execute();
	}

	private function updateMultifactorAuthentication()
	{
		$loginguardPluginPath = JPATH_ROOT . '/templates/diler3/html/com_loginguard';
		if (Folder::exists($loginguardPluginPath)){

		Folder::delete($loginguardPluginPath);

			$this->removeAkeebaLoginGuard();
			$this->enableMultifactor();
			$this->enableOnboardNewUsers();
			$this->checkOverrides();
		}
	}

	private function removeAkeebaLoginGuard()
	{
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		$elements = ['loginguard', 'com_loginguard', 'pkg_loginguard'];

		$conditions = $db->quoteName('element') . ' IN (' . implode(',', array_map([$db, 'quote'], $elements)) . ')';
		$query->delete($db->quoteName('#__extensions'))->where($conditions);
		$db->setQuery($query);

		try
		{
			$db->execute();
		}
		catch (\RuntimeException $e)
		{
			Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
		}
	}

	private function checkOverrides()
	{
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		$query->update($db->quoteName('#__template_overrides'))
			->set($db->quoteName('state') . ' = 1')
			->where($db->quoteName('state') . ' = 0');
		$db->setQuery($query)->execute();
	}




	private function enableMultifactor()
	{
		$db    = Factory::getDbo();
		$query = $db->getQuery(true);
		$query->update($db->quoteName('#__extensions'));
		$query->set($db->quoteName('enabled') . ' = 1');
		$query->where($db->quoteName('folder') . ' = ' . $db->quote('multifactorauth'));
		$db->setQuery($query)->execute();
	}

	private function enableOnboardNewUsers()
	{
		$db    = Factory::getDbo();
		$query = $db->getQuery(true);
		$query->select($db->quoteName('params'));
		$query->from($db->quoteName('#__extensions'));
		$query->where($db->quoteName('name') . ' = ' . $db->quote('com_users'));
		$db->setQuery($query);
		$currentParams = $db->loadResult();

		if (str_contains($currentParams, '"mfaredirectonlogin":"1"'))
		{
			$query = $db->getQuery(true);
			$query->update($db->quoteName('#__extensions'));
			$query->set($db->quoteName('params') . ' = REPLACE(' . $db->quoteName('params') . ', ' . $db->quote('"mfaredirectonlogin":"1"') . ', ' . $db->quote('"mfaredirectonlogin":"0"') . ')');
			$query->where($db->quoteName('name') . ' = ' . $db->quote('com_users'));
			$db->setQuery($query)->execute();

		}
	}

	private function importTermsAndConditions()
	{
		$settings = DiLerSettings::init();

		$termAndConditionsInstallPath = $settings->getRootFileFolder() . '/termsAndConditions';
		$termAndConditionsPath = JPATH_ROOT . '/administrator/components/com_dilerreg/assets/termsAndConditions';

		if (!Folder::exists($termAndConditionsInstallPath))
			Folder::create($termAndConditionsInstallPath);


		if (Folder::exists($termAndConditionsPath))
		{
			$files = Folder::files($termAndConditionsPath, '.', false, true);

			foreach ($files as $file)
			{
				$filename = basename($file);
				$newFilePath = $termAndConditionsInstallPath . '/' . $filename;

				if (!File::move($file, $newFilePath))
				{
					Factory::getApplication()->enqueueMessage("Failed to move file: " . $file, 'error');
				}
			}

			$subfolders = Folder::folders($termAndConditionsPath);
			foreach ($subfolders as $folder)
			{
				Folder::delete($folder);
			}

			if (!Folder::delete($termAndConditionsPath))
			{
				Factory::getApplication()->enqueueMessage("Failed to delete the folder: " . $termAndConditionsPath, 'error');
			}
		}
	}

    private function getParentAndStudentUsers()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('du.user_id');
        $query->from('#__dilerreg_users AS du');
        $query->innerJoin('#__users as ju ON du.user_id = ju.id');
        $query->where('du.role IN ("student", "parent")');
        return $db->setQuery($query)->loadColumn();
    }

    private function blockParentAndStudentUsers()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__users AS ju');
        $query->innerJoin('#__dilerreg_users as du ON du.user_id = ju.id');
        $query->set('block = 1');
        $query->where('du.role IN ("student", "parent")');

        return $db->setQuery($query)->execute();
    }

    private function insertDigluBlockedUser()
    {
        $parentAndStudentUserId = $this->getParentAndStudentUsers();
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->insert('#__diler_diglu_blocked_users');
        $query->columns('user_id');
        $query->values($parentAndStudentUserId);

        return $db->setQuery($query)->execute();
    }

    private function changeAccessAndParentIdForTypeOfGuardians()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->update('#__categories');
        $query->set('parent_id = 1');
        $query->set('access = 1');
        $query->where('extension = "com_diler.relationships"');

        return $db->setQuery($query)->execute();
    }
}