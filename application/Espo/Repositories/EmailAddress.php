<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2020 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: https://www.espocrm.com
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

namespace Espo\Repositories;

use Espo\ORM\Entity;

use Espo\Entities\EmailAddress as EmailAddressEntity;

use Espo\Core\Di;

class EmailAddress extends \Espo\Core\Repositories\Database implements
    Di\UserAware,
    Di\AclManagerAware
{
    use Di\UserSetter;
    use Di\AclManagerSetter;

    protected $processFieldsAfterSaveDisabled = true;

    protected $processFieldsAfterRemoveDisabled = true;

    public function getIdListFormAddressList(array $addressList = [])
    {
        return $this->getIds($addressList);
    }

    public function getIds(array $addressList = [])
    {
        $ids = [];
        if (!empty($addressList)) {
            $lowerAddressList = [];
            foreach ($addressList as $address) {
                $lowerAddressList[] = trim(strtolower($address));
            }

            $eaCollection = $this->where([
                [
                    'lower' => $lowerAddressList
                ]
            ])->find();

            $ids = [];
            $exist = [];
            foreach ($eaCollection as $ea) {
                $ids[] = $ea->id;
                $exist[] = $ea->get('lower');
            }
            foreach ($addressList as $address) {
                $address = trim($address);
                if (empty($address) || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                if (!in_array(strtolower($address), $exist)) {
                    $ea = $this->get();
                    $ea->set('name', $address);
                    $this->save($ea);
                    $ids[] = $ea->id;
                }
            }
        }
        return $ids;
    }

    public function getEmailAddressData(Entity $entity) : array
    {
        $dataList = [];

        $emailAddressList = $this
            ->select(['name', 'lower', 'invalid', 'optOut', ['ee.primary', 'primary']])
            ->join([[
                'EntityEmailAddress',
                'ee',
                [
                    'ee.emailAddressId:' => 'id',
                ]
            ]])
            ->where([
                'ee.entityId' => $entity->id,
                'ee.entityType' => $entity->getEntityType(),
                'ee.deleted' => false,
            ])
            ->order('ee.primary', true)
            ->find();

        foreach ($emailAddressList as $emailAddress) {
            $item = (object) [
                'emailAddress' => $emailAddress->get('name'),
                'lower' => $emailAddress->get('lower'),
                'primary' => $emailAddress->get('primary'),
                'optOut' => $emailAddress->get('optOut'),
                'invalid' => $emailAddress->get('invalid'),
            ];
            $dataList[] = $item;
        }

        return $dataList;
    }

    public function getByAddress(string $address) : ?EmailAddressEntity
    {
        return $this->where(['lower' => strtolower($address)])->findOne();
    }

    public function getEntityListByAddressId(
        string $emailAddressId, ?Entity $exceptionEntity = null, ?string $entityType = null, bool $onlyName = false
    ) : array {
        $entityList = [];

        $where = [
            'emailAddressId' => $emailAddressId,
        ];

        if ($exceptionEntity) {
            $where[] = [
                'OR' => [
                    'entityType!=' => $exceptionEntity->getEntityType(),
                    'entityId!=' => $exceptionEntity->id,
                ]
            ];
        }

        if ($entityType) {
            $where[] = [
                'entityType' => $entityType,
            ];
        }

        $itemList = $this->getEntityManager()->getRepository('EntityEmailAddress')
            ->sth()
            ->select(['entityType', 'entityId'])
            ->where($where)
            ->find();

        foreach ($itemList as $item) {
            $itemEntityType = $item->get('entityType');
            $itemEntityId = $item->get('entityId');

            if (!$itemEntityType || !$itemEntityId) continue;

            if (!$this->getEntityManager()->hasRepository($itemEntityType)) continue;

            if ($onlyName) {
                $select = ['id', 'name'];
                if ($itemEntityType === 'User') {
                    $select[] = 'isActive';
                }
                $entity = $this->getEntityManager()->getRepository($itemEntityType)
                    ->select($select)
                    ->where(['id' => $itemEntityId])
                    ->findOne();
            } else {
                $entity = $this->getEntityManager()->getEntity($itemEntityType, $itemEntityId);
            }

            if (!$entity) {
                continue;
            }

            if ($entity->getEntityType() === 'User' && !$entity->get('isActive')) {
                continue;
            }

            $entityList[] = $entity;
        }

        return $entityList;
    }

    public function getEntityByAddressId(string $emailAddressId, ?string $entityType = null, bool $onlyName = false) : Entity
    {
        $pdo = $this->getEntityManager()->getPDO();
        $sql = "
            SELECT entity_email_address.entity_type AS 'entityType', entity_email_address.entity_id AS 'entityId'
            FROM entity_email_address
            WHERE
                entity_email_address.email_address_id = ".$pdo->quote($emailAddressId)." AND
                entity_email_address.deleted = 0
        ";

        if ($entityType) {
            $sql .= "
                AND entity_email_address.entity_type = " . $pdo->quote($entityType) . "
            ";
        }

        $sql .= "
            ORDER BY entity_email_address.primary DESC, FIELD(entity_email_address.entity_type, 'User', 'Contact', 'Lead', 'Account')
        ";

        $sql .= " LIMIT 0, 20";

        $sth = $pdo->prepare($sql);
        $sth->execute();

        while ($row = $sth->fetch()) {
            if (empty($row['entityType']) || empty($row['entityId'])) continue;
            if (!$this->getEntityManager()->hasRepository($row['entityType'])) continue;

            if ($onlyName) {
                $select = ['id', 'name'];
                if ($row['entityType'] === 'User') {
                    $select[] = 'isActive';
                }
                $entity = $this->getEntityManager()->getRepository($row['entityType'])
                    ->select($select)
                    ->where(['id' => $row['entityId']])
                    ->findOne();
            } else {
                $entity = $this->getEntityManager()->getEntity($row['entityType'], $row['entityId']);
            }
            if ($entity) {
                if ($entity->getEntityType() === 'User') {
                    if (!$entity->get('isActive')) continue;
                }
                return $entity;
            }
        }

        return null;
    }

    public function getEntityByAddress($address, $entityType = null, $order = ['User', 'Contact', 'Lead', 'Account'])
    {
        $pdo = $this->getEntityManager()->getPDO();
        $a = [];
        foreach ($order as $item) {
            $a[] = $pdo->quote($item);
        }

        $sql = "
            SELECT entity_email_address.entity_type AS 'entityType', entity_email_address.entity_id AS 'entityId'
            FROM entity_email_address
            JOIN email_address ON email_address.id = entity_email_address.email_address_id AND email_address.deleted = 0
            WHERE
                email_address.lower = ".$pdo->quote(strtolower($address))." AND
                entity_email_address.deleted = 0
        ";

        if ($entityType) {
            $sql .= "
                AND entity_email_address.entity_type = " . $pdo->quote($entityType) . "
            ";
        }

        $sql .= "
            ORDER BY FIELD(entity_email_address.entity_type, ".join(',', array_reverse($a)).") DESC, entity_email_address.primary DESC
        ";

        $sth = $pdo->prepare($sql);
        $sth->execute();
        while ($row = $sth->fetch()) {
            if (!empty($row['entityType']) && !empty($row['entityId'])) {
                $entity = $this->getEntityManager()->getEntity($row['entityType'], $row['entityId']);
                if ($entity) {
                    return $entity;
                }
            }
        }
    }

    public function storeEntityEmailAddressData(Entity $entity)
    {
        $pdo = $this->getEntityManager()->getPDO();

        $emailAddressValue = $entity->get('emailAddress');
        if (is_string($emailAddressValue)) {
            $emailAddressValue = trim($emailAddressValue);
        }

        $emailAddressData = null;
        if ($entity->has('emailAddressData')) {
            $emailAddressData = $entity->get('emailAddressData');
        }

        if (is_null($emailAddressData)) return;
        if (!is_array($emailAddressData)) return;

        $keyList = [];
        $keyPreviousList = [];

        $previousEmailAddressData = [];
        if (!$entity->isNew()) {
            $previousEmailAddressData = $this->getEmailAddressData($entity);
        }

        $hash = (object) [];
        $hashPrevious = (object) [];

        foreach ($emailAddressData as $row) {
            $key = trim($row->emailAddress);
            if (empty($key)) continue;
            $key = strtolower($key);
            $hash->$key = [
                'primary' => !empty($row->primary) ? true : false,
                'optOut' => !empty($row->optOut) ? true : false,
                'invalid' => !empty($row->invalid) ? true : false,
                'emailAddress' => trim($row->emailAddress)
            ];
            $keyList[] = $key;
        }

        if (
            $entity->has('emailAddressIsOptedOut')
            &&
            (
                $entity->isNew()
                ||
                (
                    $entity->hasFetched('emailAddressIsOptedOut')
                    &&
                    $entity->get('emailAddressIsOptedOut') !== $entity->getFetched('emailAddressIsOptedOut')
                )
            )
        ) {
            if ($emailAddressValue) {
                $key = strtolower($emailAddressValue);
                if ($key && isset($hash->$key)) {
                    $hash->{$key}['optOut'] = $entity->get('emailAddressIsOptedOut');
                }
            }
        }

        foreach ($previousEmailAddressData as $row) {
            $key = $row->lower;
            if (empty($key)) continue;
            $hashPrevious->$key = [
                'primary' => $row->primary ? true : false,
                'optOut' => $row->optOut ? true : false,
                'invalid' => $row->invalid ? true : false,
                'emailAddress' => $row->emailAddress
            ];
            $keyPreviousList[] = $key;
        }

        $primary = false;

        $toCreateList = [];
        $toUpdateList = [];
        $toRemoveList = [];

        $revertData = [];

        foreach ($keyList as $key) {
            $data = $hash->$key;

            $new = true;
            $changed = false;

            if ($hash->{$key}['primary']) {
                $primary = $key;
            }

            if (property_exists($hashPrevious, $key)) {
                $new = false;
                $changed =
                    $hash->{$key}['optOut'] != $hashPrevious->{$key}['optOut'] ||
                    $hash->{$key}['invalid'] != $hashPrevious->{$key}['invalid'] ||
                    $hash->{$key}['emailAddress'] !== $hashPrevious->{$key}['emailAddress'];

                if ($hash->{$key}['primary']) {
                    if ($hash->{$key}['primary'] == $hashPrevious->{$key}['primary']) {
                        $primary = false;
                    }
                }
            }

            if ($new) {
                $toCreateList[] = $key;
            }
            if ($changed) {
                $toUpdateList[] = $key;
            }
        }

        foreach ($keyPreviousList as $key) {
            if (!property_exists($hash, $key)) {
                $toRemoveList[] = $key;
            }
        }

        foreach ($toRemoveList as $address) {
            $emailAddress = $this->getByAddress($address);
            if ($emailAddress) {
                $query = "
                    DELETE FROM entity_email_address
                    WHERE
                        entity_id = ".$pdo->quote($entity->id)." AND
                        entity_type = ".$pdo->quote($entity->getEntityType())." AND
                        email_address_id = ".$pdo->quote($emailAddress->id)."
                ";
                $sth = $pdo->prepare($query);
                $sth->execute();
            }
        }

        foreach ($toUpdateList as $address) {
            $emailAddress = $this->getByAddress($address);
            if ($emailAddress) {
                $skipSave = $this->checkChangeIsForbidden($emailAddress, $entity);
                if (!$skipSave) {
                    $emailAddress->set([
                        'optOut' => $hash->{$address}['optOut'],
                        'invalid' => $hash->{$address}['invalid'],
                        'name' => $hash->{$address}['emailAddress']
                    ]);
                    $this->save($emailAddress);
                } else {
                    $revertData[$address] = [
                        'optOut' => $emailAddress->get('optOut'),
                        'invalid' => $emailAddress->get('invalid'),
                    ];
                }
            }
        }

        foreach ($toCreateList as $address) {
            $emailAddress = $this->getByAddress($address);
            if (!$emailAddress) {
                $emailAddress = $this->get();

                $emailAddress->set([
                    'name' => $hash->{$address}['emailAddress'],
                    'optOut' => $hash->{$address}['optOut'],
                    'invalid' => $hash->{$address}['invalid'],
                ]);
                $this->save($emailAddress);
            } else {
                $skipSave = $this->checkChangeIsForbidden($emailAddress, $entity);
                if (!$skipSave) {
                    if (
                        $emailAddress->get('optOut') != $hash->{$address}['optOut'] ||
                        $emailAddress->get('invalid') != $hash->{$address}['invalid'] ||
                        $emailAddress->get('emailAddress') != $hash->{$address}['emailAddress']
                    ) {
                        $emailAddress->set([
                            'optOut' => $hash->{$address}['optOut'],
                            'invalid' => $hash->{$address}['invalid'],
                            'name' => $hash->{$address}['emailAddress']
                        ]);
                        $this->save($emailAddress);
                    }
                } else {
                    $revertData[$address] = [
                        'optOut' => $emailAddress->get('optOut'),
                        'invalid' => $emailAddress->get('invalid')
                    ];
                }
            }

            $query = "
                INSERT entity_email_address
                    (entity_id, entity_type, email_address_id, `primary`)
                    VALUES
                    (
                        ".$pdo->quote($entity->id).",
                        ".$pdo->quote($entity->getEntityType()).",
                        ".$pdo->quote($emailAddress->id).",
                        ".$pdo->quote((int)($address === $primary))."
                    )
                ON DUPLICATE KEY UPDATE deleted = 0, `primary` = ".$pdo->quote((int)($address === $primary))."
            ";

            $this->getEntityManager()->runQuery($query, true);
        }

        if ($primary) {
            $emailAddress = $this->getByAddress($primary);
            if ($emailAddress) {
                $query = "
                    UPDATE entity_email_address
                    SET `primary` = 0
                    WHERE
                        entity_id = ".$pdo->quote($entity->id)." AND
                        entity_type = ".$pdo->quote($entity->getEntityType())." AND
                        `primary` = 1 AND
                        deleted = 0
                ";
                $sth = $pdo->prepare($query);
                $sth->execute();

                $query = "
                    UPDATE entity_email_address
                    SET `primary` = 1
                    WHERE
                        entity_id = ".$pdo->quote($entity->id)." AND
                        entity_type = ".$pdo->quote($entity->getEntityType())." AND
                        email_address_id = ".$pdo->quote($emailAddress->id)." AND
                        deleted = 0
                ";
                $sth = $pdo->prepare($query);
                $sth->execute();
            }
        }

        if (!empty($revertData)) {
            foreach ($emailAddressData as $row) {
                if (!empty($revertData[$row->emailAddress])) {
                    $row->optOut = $revertData[$row->emailAddress]['optOut'];
                    $row->invalid = $revertData[$row->emailAddress]['invalid'];
                }
            }
            $entity->set('emailAddressData', $emailAddressData);
        }
    }

    protected function storeEntityEmailAddressPrimary(Entity $entity)
    {
        if (!$entity->has('emailAddress')) return;

        $pdo = $this->getEntityManager()->getPDO();

        $emailAddressValue = $entity->get('emailAddress');
        if (is_string($emailAddressValue)) {
            $emailAddressValue = trim($emailAddressValue);
        }

        $entityRepository = $this->getEntityManager()->getRepository($entity->getEntityType());
        if (!empty($emailAddressValue)) {
            if ($emailAddressValue != $entity->getFetched('emailAddress')) {

                $emailAddressNew = $this->where(['lower' => strtolower($emailAddressValue)])->findOne();
                $isNewEmailAddress = false;
                if (!$emailAddressNew) {
                    $emailAddressNew = $this->get();
                    $emailAddressNew->set('name', $emailAddressValue);
                    if ($entity->has('emailAddressIsOptedOut')) {
                        $emailAddressNew->set('optOut', !!$entity->get('emailAddressIsOptedOut'));
                    }
                    $this->save($emailAddressNew);
                    $isNewEmailAddress = true;
                }

                $emailAddressValueOld = $entity->getFetched('emailAddress');
                if (!empty($emailAddressValueOld)) {
                    $emailAddressOld = $this->getByAddress($emailAddressValueOld);
                    if ($emailAddressOld) {
                        $entityRepository->unrelate($entity, 'emailAddresses', $emailAddressOld);
                    }
                }
                $entityRepository->relate($entity, 'emailAddresses', $emailAddressNew);

                if ($entity->has('emailAddressIsOptedOut')) {
                    $this->markAddressOptedOut($emailAddressValue, !!$entity->get('emailAddressIsOptedOut'));
                }

                $query = "
                    UPDATE entity_email_address
                    SET `primary` = 1
                    WHERE
                        entity_id = ".$pdo->quote($entity->id)." AND
                        entity_type = ".$pdo->quote($entity->getEntityType())." AND
                        email_address_id = ".$pdo->quote($emailAddressNew->id)."
                ";
                $sth = $pdo->prepare($query);
                $sth->execute();
            } else {
                if (
                    $entity->has('emailAddressIsOptedOut')
                    &&
                    (
                        $entity->isNew()
                        ||
                        (
                            $entity->hasFetched('emailAddressIsOptedOut')
                            &&
                            $entity->get('emailAddressIsOptedOut') !== $entity->getFetched('emailAddressIsOptedOut')
                        )
                    )
                ) {
                    $this->markAddressOptedOut($emailAddressValue, !!$entity->get('emailAddressIsOptedOut'));
                }
            }
        } else {
            $emailAddressValueOld = $entity->getFetched('emailAddress');
            if (!empty($emailAddressValueOld)) {
                $emailAddressOld = $this->getByAddress($emailAddressValueOld);
                if ($emailAddressOld) {
                    $entityRepository->unrelate($entity, 'emailAddresses', $emailAddressOld);
                }
            }
        }

    }

    public function storeEntityEmailAddress(Entity $entity)
    {
        $emailAddressData = null;
        if ($entity->has('emailAddressData')) {
            $emailAddressData = $entity->get('emailAddressData');
        }

        if ($emailAddressData !== null) {
            $this->storeEntityEmailAddressData($entity);
        } else if ($entity->has('emailAddress')) {
            $this->storeEntityEmailAddressPrimary($entity);
        }
    }

    // TODO move it to another place
    protected function checkChangeIsForbidden($entity, $excludeEntity)
    {
        return !$this->aclManager->getImplementation('EmailAddress')
            ->checkEditInEntity($this->user, $entity, $excludeEntity);
    }

    public function markAddressOptedOut($address, $isOptedOut = true)
    {
        $emailAddress = $this->getByAddress($address);
        if ($emailAddress) {
            $emailAddress->set('optOut', !!$isOptedOut);
            $this->save($emailAddress);
        }
    }
}
