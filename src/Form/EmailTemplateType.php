<?php

namespace App\Form;

use App\Entity\EmailTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmailTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, [
                'label' => 'Nom du modèle',
                'attr' => ['placeholder' => 'ex: Bienvenue Étudiant']
            ])
            ->add('subject', null, [
                'label' => 'Sujet de l\'email',
                'attr' => ['placeholder' => 'ex: Votre site est prêt !']
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Contenu du message',
                'attr' => ['rows' => 10, 'placeholder' => 'Bonjour [prenom], votre site est disponible ici : [url]']
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmailTemplate::class,
        ]);
    }
}
