<?php

namespace App\Form;

use App\Entity\Site;
use Symfony\Component\Form\AbstractType;
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
                    'WordPress' => 'wordpress',
                    'Site Vierge (HTML/PHP)' => 'static',
                ],
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
