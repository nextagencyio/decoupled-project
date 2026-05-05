<?php

declare(strict_types=1);

namespace Drupal\dc_form\Plugin\GraphQL\SchemaExtension;

use Drupal\contact\ContactFormInterface;
use Drupal\graphql\GraphQL\ResolverBuilder;
use Drupal\graphql\GraphQL\ResolverRegistryInterface;
use Drupal\graphql_compose\Plugin\GraphQL\SchemaExtension\ResolverOnlySchemaExtensionPluginBase;

/**
 * Resolvers for the ContactForm type registered by dc_form's
 * EntityType plugin. Pattern matches graphql_compose_menus's
 * MenusSchemaExtension.
 *
 * @SchemaExtension(
 *   id = "dc_form",
 *   name = "Decoupled Form",
 *   description = @Translation("Add contact_form to the GraphQL schema."),
 *   schema = "graphql_compose",
 * )
 */
class ContactFormSchemaExtension extends ResolverOnlySchemaExtensionPluginBase {

  /**
   * {@inheritdoc}
   */
  public function registerResolvers(ResolverRegistryInterface $registry): void {
    $builder = new ResolverBuilder();

    // Top-level Query.contactForm(id: ID!): ContactForm.
    //
    // Direct callback rather than the `entity_load` data producer
    // because that producer runs an access check (defaults to
    // `view`), and contact_form's `view label` permission is
    // restricted to admin out of the box. Make-published forms are
    // intended to be queryable by the headless consumer (anonymous
    // public site visitors) — the form's content is structural
    // metadata, not user data — so we skip the per-entity ACL.
    $registry->addFieldResolver(
      'Query',
      'contactForm',
      $builder->callback(function ($parent, $args) {
        $id = $args['id'] ?? '';
        if (!is_string($id) || $id === '') return NULL;
        return \Drupal::entityTypeManager()->getStorage('contact_form')->load($id);
      }),
    );

    // ContactForm.id — config-entity ID is the machine name.
    $registry->addFieldResolver('ContactForm', 'id',
      $builder->callback(fn(ContactFormInterface $form) => $form->id()),
    );

    // ContactForm.label — direct callback. The graphql_compose
    // `entity_label` data producer expects content entities and
    // returns null for config entities; calling label() ourselves is
    // the straightforward path.
    $registry->addFieldResolver('ContactForm', 'label',
      $builder->callback(fn(ContactFormInterface $form) => (string) $form->label()),
    );

    // ContactForm.recipients — array of email strings, straight off
    // the contact_form config entity.
    $registry->addFieldResolver('ContactForm', 'recipients',
      $builder->callback(fn(ContactFormInterface $form) => $form->getRecipients()),
    );
  }

}
