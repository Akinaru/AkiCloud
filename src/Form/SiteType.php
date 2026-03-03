<?php

namespace App\Form;

use App\Entity\Site;
use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
            ->add('deploymentSource', ChoiceType::class, [
                'label' => 'Source de déploiement',
                'choices' => [
                    'Git public' => Site::SOURCE_GIT_PUBLIC,
                    'Volume local machine' => Site::SOURCE_LOCAL_VOLUME,
                ],
            ])
            ->add('gitRepository', null, [
                'label' => 'Dépôt Git public',
                'required' => false,
                'attr' => ['placeholder' => 'https://github.com/owner/repo.git'],
            ])
            ->add('customDomain', TextType::class, [
                'label' => 'Nom de domaine custom',
                'required' => false,
                'attr' => ['placeholder' => 'ex: app.mondomaine.fr'],
            ])
            ->add('publishDirectory', null, [
                'label' => 'Dossier de publication',
                'required' => false,
                'attr' => ['placeholder' => '/'],
            ])
            ->add('createDatabase', CheckboxType::class, [
                'required' => false,
                'label' => 'Créer une base de données liée au site',
            ])
            ->add('isProtected', CheckboxType::class, [
                'required' => false,
                'label' => 'Protéger ce site (accès réservé aux utilisateurs autorisés)',
                'help' => 'Si activé, le site n est accessible qu aux utilisateurs liés au site.',
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
            ->add('authorizedUsers', EntityType::class, [
                'class' => User::class,
                'label' => 'Utilisateurs autorisés',
                'choice_label' => fn (User $user) => sprintf('%s (%s)', $user->getFullName(), $user->getEmail()),
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'size' => 8,
                ],
                'help' => 'Les utilisateurs sélectionnés verront ce site dans leur middle-office.',
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
