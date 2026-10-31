# Vendor response fixtures

Response bodies copied from the core's adapter fixtures (mtubis/html-translator,
`tests/Unit/Provider/Llm/Bridge/{OpenAiCompatible,Anthropic}/Fixtures/`). The `success` bodies carry one translated
unit, `{"id": "u1", "text": "Cześć"}`, which matches the single unit of `<p>Hello</p>`; everything else is as in the
core. Refresh them from the core when its fixtures change.
