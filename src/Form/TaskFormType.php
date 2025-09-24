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
                    '15 mins' => 15,
                    '30 mins' => 30,
                    '45 mins' => 45,
                    '1 hour' => 60,
                    '1.5 hours' => 90,
                    '2 hours' => 120,
                    '3 hours' => 180,
                    '4 hours' => 240,
                    '6 hours' => 360,
                    '8 hours' => 480,
                    '12 hours' => 720,
                    '24 hours' => 1440,
                ],
            ])
            ->add('eventMinDuration', ChoiceType::class, [
                'label' => 'Event min duration',
                'choices' => [
                    '15 mins' => 15,
                    '30 mins' => 30,
                    '45 mins' => 45,
                    '1 hour' => 60,
                    '1.5 hours' => 90,
                    '2 hours' => 120,
                    '3 hours' => 180,
                    '4 hours' => 240,
                    '6 hours' => 360,
                    '8 hours' => 480,
                    '12 hours' => 720,
                    '24 hours' => 1440,
                ],
            ])
            ->add('eventMaxDuration', ChoiceType::class, [
                'label' => 'Event max duration',
                'choices' => [
                    '15 mins' => 15,
                    '30 mins' => 30,
                    '45 mins' => 45,
                    '1 hour' => 60,
                    '1.5 hours' => 90,
                    '2 hours' => 120,
                    '3 hours' => 180,
                    '4 hours' => 240,
                    '6 hours' => 360,
                    '8 hours' => 480,
                    '12 hours' => 720,
                    '24 hours' => 1440,
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
                // model → view (seconds → minutes)
                function ($secondsToMinutes) {
                    return $secondsToMinutes ? $secondsToMinutes / 60 : '';
                },
                // view → model (minutes → seconds)
                function ($minutesToSeconds) {
                    return null !== $minutesToSeconds ? $minutesToSeconds * 60 : null;
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
