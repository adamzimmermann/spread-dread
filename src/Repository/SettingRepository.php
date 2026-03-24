<?php

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Setting> */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    public function get(string $key, string $default = ''): string
    {
        $setting = $this->find($key);
        return $setting ? $setting->getValue() : $default;
    }

    public function set(string $key, string $value): void
    {
        $em = $this->getEntityManager();
        $setting = $this->find($key);
        if ($setting) {
            $setting->setValue($value);
        } else {
            $setting = new Setting($key, $value);
            $em->persist($setting);
        }
        $em->flush();
    }
}
