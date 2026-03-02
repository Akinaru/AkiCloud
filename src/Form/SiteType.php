<?php

namespace App\Form;

use App\Entity\Site;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, [
                'label' => 'Nom du site',
                'attr' => ['placeholder' => 'ex: Mon Super Projet']
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type de stack',
                'choices' => [
                    'PHP' => 'php',
                    'Node.js' => 'node',
                    'Statique' => 'static',
                ],
            ])
            ->add('gitRepository', null, [
                'label' => 'Dépôt Git personnalisé',
                'required' => false,
                'attr' => ['placeholder' => 'Optionnel - ex: git@github.com:owner/repo.git'],
            ])
            ->add('publishDirectory', null, [
                'label' => 'Dossier de publication',
                'required' => false,
                'attr' => ['placeholder' => '/'],
            ])
            ->add('ownerDifferent', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Le proprietaire est different de moi',
            ])
            ->add('port', null, [
                'label' => 'Port (Optionnel)',
                'attr' => ['placeholder' => '80']
            ])
            ->add('ownerFirstname', null, ['label' => 'Prénom propriétaire (Optionnel)'])
            ->add('ownerLastname', null, ['label' => 'Nom propriétaire (Optionnel)'])
            ->add('ownerEmail', null, ['label' => 'Email propriétaire (Optionnel)'])
            ->add('pendingEmailTemplate', \Symfony\Bridge\Doctrine\Form\Type\EntityType::class, [
                'class' => \App\Entity\EmailTemplate::class,
                'choice_label' => 'name',
                'label' => 'Modèle d\'email de notification',
                'placeholder' => 'Ne pas envoyer d\'email',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Site::class,
        ]);
    }
}
