# Kinds of content

Every collection can be written for straight away, with a general brief. A **kind** is something you write often in a collection, such as a case study, a guide or a technology page, with a brief of its own:

- **Questions** asked before anything is written, about the facts that can't be invented.
- **Guidance** for the writer: who the reader is, what each part does and in what order, how long it runs.
- **A checklist** of things that must be true of a finished draft.
- **Examples**: entries the kind is modelled on, for structure, length and how blocks are used.

## Suggested kinds

Ghostwriter looks at a collection's entries (their titles, how they are built, how they open) and suggests the kinds of content in it, with a line on why each one is worth teaching.

- It looks **by itself** the first time a collection appears on the dashboard, and again once ten or more entries have been published there since. Each look is one model call. Turn this off with **Suggest kinds of content** in the settings.
- **Suggest kinds** in a collection's **Kinds** menu on the dashboard asks again now.

Suggestions appear under the collection as **N suggested kinds to review**. Open it, then:

- **Learn this** writes the brief for that kind from the entries it was suggested from. It takes about a minute.
- **Not this** dismisses it. Dismissed kinds aren't suggested again.
- **Learn all N** learns every suggestion in the collection, one after another. One failing does not stop the rest; failures are reported by name.

The Get started page shows the same suggestions on its fourth step.

## Teaching a kind yourself

**Teach it a kind** (in a collection's **Kinds** menu, or in the writing panel) teaches one by hand:

1. **What is this kind of content called?** For example "Case study". Leave it blank and Ghostwriter names it.
2. **Model it on**: tick up to six entries that are good examples, or leave them unticked to use the newest published entries.

Ghostwriter writes the questions, guidance and checklist from those entries. Two kinds with the same name get their own handles (`event`, `event-2`); nothing is overwritten.

## Editing a kind

Click a kind on the dashboard to open it. You can change:

- **Name** and **Description** (shown when choosing what to write).
- **The brief**: the questions, each with a hint, and whether it must be answered. Ask only for what can't be invented: what happened, who for, what resulted, what must be left out.
- **Guidance for the writer**, in markdown. The fields themselves are read from the blueprint, so the guidance doesn't need to list them.
- **Check before handing over**: one statement per line.
- **Modelled on**: the example entries.

**Delete** removes the kind; entries already written with it are not affected.

## Where kinds are kept

Each kind is a YAML file in `resources/ghostwriter/types/`, so it can be versioned with your project and edited by hand:

```yaml
title: Case study
description: Tells one project from start to finish.
collection: case_studies
blueprint: case_study          # optional, for collections with several
examples: [entry-id, ...]      # optional: model the kind on these entries
where: { categories: guides }  # optional: learn only from entries matching this
defaults: { categories: [guides] }   # optional: entry data set on everything written
questions:
  - handle: client
    label: Who is the client?
    type: text                 # text or textarea
    required: true
guidance: |
  Markdown: who the reader is, what each part does and in what order, how long it runs.
checklist:
  - The result states only outcomes given in the brief.
```
