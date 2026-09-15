<?php

namespace App\Controller\Admin;

use App\Entity\BlockCheck;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BlockCheckCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BlockCheck::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Block check')
            ->setEntityLabelInPlural('Block checks')
            ->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield DateTimeField::new('date');
        yield TextField::new('ip');
    }
}
