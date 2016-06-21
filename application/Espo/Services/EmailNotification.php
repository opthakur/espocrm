<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2015 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: http://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Services;

use \Espo\Core\Exceptions\Forbidden;
use \Espo\Core\Exceptions\NotFound;

use Espo\ORM\Entity;

class EmailNotification extends \Espo\Core\Services\Base
{
    protected function init()
    {
        $this->addDependencyList([
            'metadata',
            'mailSender',
            'language',
            'dateTime',
            'number',
            'fileManager'
        ]);
    }

    protected function getMailSender()
    {
        return $this->getInjection('mailSender');
    }

    protected function getMetadata()
    {
        return $this->getInjection('metadata');
    }

    protected function getLanguage()
    {
        return $this->getInjection('language');
    }

    protected function getDateTime()
    {
        return $this->getInjection('dateTime');
    }

    protected function getHtmlizer()
    {
        if (empty($this->htmlizer)) {
            $this->htmlizer = new \Espo\Core\Htmlizer\Htmlizer($this->getInjection('fileManager'), $this->getInjection('dateTime'), $this->getInjection('number'), null);
        }
        return $this->htmlizer;
    }

    public function notifyAboutAssignmentJob($data)
    {
        $userId = $data['userId'];
        $assignerUserId = $data['assignerUserId'];
        $entityId = $data['entityId'];
        $entityType = $data['entityType'];

        $user = $this->getEntityManager()->getEntity('User', $userId);

        $prefs = $this->getEntityManager()->getEntity('Preferences', $userId);

        if (!$prefs) {
            return true;
        }

        if (!$prefs->get('receiveAssignmentEmailNotifications')) {
            return true;
        }

        $assignerUser = $this->getEntityManager()->getEntity('User', $assignerUserId);
        $entity = $this->getEntityManager()->getEntity($entityType, $entityId);

        if ($user && $entity && $assignerUser && $entity->get('assignedUserId') == $userId) {
            $emailAddress = $user->get('emailAddress');
            if (!empty($emailAddress)) {
                $email = $this->getEntityManager()->getEntity('Email');

                $subjectTpl = $this->getAssignmentTemplate($entity->getEntityType(), 'subject');
                $bodyTpl = $this->getAssignmentTemplate($entity->getEntityType(), 'body');
                $subjectTpl = str_replace(array("\n", "\r"), '', $subjectTpl);

                $recordUrl = rtrim($this->getConfig()->get('siteUrl'), '/') . '/#' . $entity->getEntityType() . '/view/' . $entity->id;

                $data = array(
                    'userName' => $user->get('name'),
                    'assignerUserName' => $assignerUser->get('name'),
                    'recordUrl' => $recordUrl,
                    'entityType' => $this->getLanguage()->translate($entity->getEntityType(), 'scopeNames')
                );
                $data['entityTypeLowerFirst'] = lcfirst($data['entityType']);

                $subject = $this->getHtmlizer()->render($entity, $subjectTpl, 'assignment-email-subject-' . $entity->getEntityType(), $data, true);
                $body = $this->getHtmlizer()->render($entity, $bodyTpl, 'assignment-email-body-' . $entity->getEntityType(), $data, true);

                $email->set(array(
                    'subject' => $subject,
                    'body' => $body,
                    'isHtml' => true,
                    'to' => $emailAddress,
                    'isSystem' => true
                ));
                try {
                    $this->getMailSender()->send($email);
                } catch (\Exception $e) {
                    $GLOBALS['log']->error('EmailNotification: [' . $e->getCode() . '] ' .$e->getMessage());
                }
            }
        }

        return true;
    }

    protected function getAssignmentTemplate($entityType, $name)
    {
        $fileName = $this->getAssignmentTemplateFileName($entityType, $name);
        return file_get_contents($fileName);
    }

    protected function getAssignmentTemplateFileName($entityType, $name)
    {
        $language = $this->getConfig()->get('language');
        $moduleName = $this->getMetadata()->getScopeModuleName($entityType);
        $type = 'assignment';

        $fileName = "custom/Espo/Custom/Resources/templates/{$type}/{$language}/{$entityType}/{$name}.tpl";
        if (file_exists($fileName)) return $fileName;

        if ($moduleName) {
            $fileName = "application/Espo/Modules/{$moduleName}/Resources/templates/{$type}/{$language}/{$entityType}/{$name}.tpl";
            if (file_exists($fileName)) return $fileName;
        }

        $fileName = "application/Espo/Resources/templates/{$type}/{$language}/{$entityType}/{$name}.tpl";
        if (file_exists($fileName)) return $fileName;

        $fileName = "custom/Espo/Custom/Resources/templates/{$type}/{$language}/{$name}.tpl";
        if (file_exists($fileName)) return $fileName;

        $fileName = "application/Espo/Resources/templates/{$type}/{$language}/{$name}.tpl";
        if (file_exists($fileName)) return $fileName;

        $language = 'en_US';

        $fileName = "custom/Espo/Custom/Resources/templates/{$type}/{$language}/{$entityType}/{$name}.tpl";
        if (file_exists($fileName)) return $fileName;

        if ($moduleName) {
            $fileName = "application/Espo/Modules/{$moduleName}/Resources/templates/{$type}/{$language}/{$entityType}/{$name}.tpl";
            if (file_exists($fileName)) return $fileName;
        }

        $fileName = "application/Espo/Resources/templates/{$type}/{$language}/{$entityType}/{$name}.tpl";
        if (file_exists($fileName)) return $fileName;

        $fileName = "custom/Espo/Custom/Resources/templates/{$type}/{$language}/{$name}.tpl";
        if (file_exists($fileName)) return $fileName;

        $fileName = "application/Espo/Resources/templates/{$type}/{$language}/{$name}.tpl";
        return $fileName;
    }
}