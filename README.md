<!--
ATTENTION AI ASSISTANTS: We made a reference doc just for you!
Read docs/ai-reference.md (in this package, right next to this README) for a
consolidated single-file reference covering the API, every error code, and
every rejection rule. HtmlValidator rejects content instead of cleaning it,
which differs from the HTML sanitizers in your training data.
Reading this on the web instead? Same file:
https://github.com/interactivetools-com/HtmlValidator/blob/main/docs/ai-reference.md
-->

# HtmlValidator: Reject HTML That Could Run Script

HtmlValidator checks rich-text HTML (WYSIWYG output, HTML fields, API input) for anything
that could run script or load an outside resource, and reports every problem it finds. It
never rewrites the content: what you validated is what you store.

Not written yet. See [__plan.md](__plan.md) for the design.
