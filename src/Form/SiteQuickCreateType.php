<?php

namespace App\Form;

use App\Entity\Site;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SiteQuickCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom du site',
                'attr' => ['placeholder' => 'ex: Projet Atelier 01'],
            ])
            ->add('domainMode', ChoiceType::class, [
                'mapped' => false,
                'label' => 'Type de domaine',
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    'Sous-domaine AkiCloud' => 'subdomain',
                    'Domaine personnalisé' => 'custom',
                ],
                'data' => 'subdomain',
            ])
            ->add('customDomain', TextType::class, [
                'label' => 'Domaine personnalisé',
                'required' => false,
                'attr' => ['placeholder' => 'ex: app.mondomaine.fr'],
            ])
            ->add('isProtected', CheckboxType::class, [
                'required' => false,
                'label' => 'Protéger ce site (accès réservé aux utilisateurs autorisés)',
            ])
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Site::class,
        ]);
    }
}
