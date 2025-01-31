<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Languages configuration for the local_sigaaintegration plugin.
 *
 * @package   local_sigaaintegration
 * @copyright 2024, Igor Ferreira Cemim
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'SIGAA Integration';
$string['settings'] = 'SIGAA Integration - Settings';
$string['apisettings'] = 'API Configuration';
$string['apisettings_information'] = 'URL and authentication credentials.';
$string['userfields_settings'] = 'User Profile Fields Settings';
$string['userfields_settings_information'] = '';
$string['coursefields_settings'] = 'Course Custom Fields Settings';
$string['coursefields_settings_information'] = '';
$string['apibaseurl'] = 'URL base';
$string['apibaseurl_information'] = 'SIGAA API base URL.';
$string['apiclientid'] = 'Client ID';
$string['apiclientid_information'] = 'SIGAA API Client ID.';
$string['apiclientsecret'] = 'Client Secret';
$string['apiclientsecret_information'] = 'SIGAA API Client Secret.';
$string['othersettings'] = 'Other Settings';
$string['cpffieldname'] = 'CPF Field Name';
$string['cpffieldname_information'] = 'Shortname of the custom field used to save teacher CPF.';
$string['periodfieldname'] = 'Period Field Name';
$string['periodfieldname_information'] = 'Shortname of the custom field used to save course period.';
$string['metadatafieldname'] = 'Metadata Field Name';
$string['metadatafieldname_information'] = 'Shortname of the custom field used to save course metadata.';
$string['basecategory'] = 'Base Category';
$string['basecategory_information'] = 'Base category used for courses import.';
$string['archivecategoryname'] = 'Archive Category Name';
$string['archivecategoryname_information'] = 'Name of the archive category.';
$string['studentroleid'] = 'Student role';
$string['studentroleid_information'] = 'Role that should be used when enrolling students into courses.';
$string['teacherroleid'] = 'Teacher role';
$string['teacherroleid_information'] = 'Role that should be used when enrolling teachers into courses.';
$string['manageintegration'] = 'SIGAA Integration - Manage Integration';
$string['period'] = 'Period (year/semester)';
$string['period_help'] = 'Enter the period for processing.';
$string['importenrollments'] = 'Import enrollments';
$string['importservantenrollments'] = 'Import teachers enrollments';
$string['importstudents'] = 'Import students';
$string['importservants'] = 'Import teachers';
$string['importcourses'] = 'Import courses';
$string['importcategories'] = 'Import categories';
$string['deleteallusers'] = 'Delete all users';
$string['deleteallcategories'] = 'Delete all categories';
$string['archivecourses'] = 'Archive courses';
$string['import'] = 'Import';
$string['archive'] = 'Archive';
$string['delete'] = 'Delete';
$string['sync_task_name'] = 'Sync Task';
$string['error:no_enrol_instance'] = 'Manual enrol plugin is disabled.';
$string['error:user_already_enrolled'] = 'User "{$a->userid}" is already enrolled into course "{$a->courseid}"';
$string['error:course_already_exists'] = 'Course already exists.';

$string['clientlist'] = 'Client list';
$string['clientlist_desc'] = 'List of campus to configure, separated by comma. Ex: alvorada, osorio, porto alegre';
$string['client_config'] = 'IFRS';

$string['id_campus'] = 'id_campus';
$string['id_campus_information'] = 'SIGAA id from SIGAA API';
$string['scheduled_sync_information'] = 'The sync will be scheduled or not';
$string['scheduled_sync'] = 'Scheduled sync';
$string['academic_period'] = 'Academic period';
$string['academic_period_information'] = 'Academic period to be synchronized. Ex: 2025/1';
$string['error_current_term_format'] = 'The academic period format "{$a}" is incorrect. Please use the format YYYY/N (where YYYY is the year and N is the semester number, 1 or 2). For more information, please refer to the documentation.';

$string['presencial'] = 'Presencial';
$string['a_distancia'] = 'A Distância';
$string['semi_presencial'] = 'Semi-Presencial';
$string['remoto'] = 'Remoto';

$string['modalidade_educacao'] = 'Modalidade Educação';
$string['modalidade_educacao_information'] = 'Modalidade Educação';

$string['coursevisibility'] = 'Course visibility';
$string['coursevisibility_desc'] = 'Set whether courses should be visible or hidden by default when created.';
$string['visible'] = 'Visible';
$string['hidden'] = 'Hidden';

$string['testmail'] = 'SIGAA Integration - Test Email';
$string['cpf'] = 'CPF';
$string['cpf_help'] = 'Enter your CPF (Brazilian individual taxpayer registry identification).';
$string['courseidnumber'] = 'Course ID Number';
$string['courseidnumber_help'] = 'Enter the course identification number.';
$string['required'] = 'This field is required.';
$string['email_sent'] = 'Email sent with the provided data.';