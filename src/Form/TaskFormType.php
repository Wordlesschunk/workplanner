<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Task;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TaskFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Task Name',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes',
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'Priority',
                'choices' => [
                    'Critical' => 'critical',
                    'High' => 'high',
                    'Medium' => 'medium',
                    'Low' => 'low',
                ],
            ])
            ->add('requiredDurationSeconds', ChoiceType::class, [
                'label' => 'Time Required',
                'attr' => ['placeholder' => 'Enter time in minutes'],
                'choices' => [
                    '15 mins' => 900,
                    '30 mins' => 1800,
                    '45 mins' => 2700,
                    '1 hour' => 3600,
                    '1.5 hours' => 5400,
                    '2 hours' => 7200,
                    '3 hours' => 10800,
                    '4 hours' => 14400,
                    '6 hours' => 21600,
                    '8 hours' => 28800,
                    '12 hours' => 43200,
                    '24 hours' => 86400,
                ],
            ])
            ->add('eventMinDurationSeconds', ChoiceType::class, [
                'label' => 'Event min duration',
                'choices' => [
                    '15 mins' => 900,
                    '30 mins' => 1800,
                    '45 mins' => 2700,
                    '1 hour' => 3600,
                    '1.5 hours' => 5400,
                    '2 hours' => 7200,
                    '3 hours' => 10800,
                    '4 hours' => 14400,
                    '6 hours' => 21600,
                    '8 hours' => 28800,
                    '12 hours' => 43200,
                    '24 hours' => 86400,
                ],
            ])
            ->add('eventMaxDurationSeconds', ChoiceType::class, [
                'label' => 'Event max duration',
                'choices' => [
                    '15 mins' => 900,
                    '30 mins' => 1800,
                    '45 mins' => 2700,
                    '1 hour' => 3600,
                    '1.5 hours' => 5400,
                    '2 hours' => 7200,
                    '3 hours' => 10800,
                    '4 hours' => 14400,
                    '6 hours' => 21600,
                    '8 hours' => 28800,
                    '12 hours' => 43200,
                    '24 hours' => 86400,
                ],
            ])
            ->add('scheduleAfter', DateTimeType::class, [
                'label' => 'Schedule After',
                'widget' => 'single_text',
            ])
            ->add('dueDate', DateTimeType::class, [
                'label' => 'Due Date',
                'widget' => 'single_text',
            ]);

        $builder->get('requiredDurationSeconds')
            ->addModelTransformer(new CallbackTransformer(
            // model → view (seconds → seconds)
                function ($seconds) {
                    return $seconds ?: '';
                },
                // view → model (seconds → seconds) 
                function ($seconds) {
                    return null !== $seconds ? $seconds : null;
                }
            ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Task::class,
        ]);
    }
}