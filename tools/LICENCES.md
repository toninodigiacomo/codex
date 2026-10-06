# Licences Codex Premium

`codex-license.php` émet les clés d'enregistrement. **Il tourne sur ta machine, pas sur le serveur de Codex.**
Il demande PHP en ligne de commande avec l'extension sodium (présente dans l'image `php:8.2-cli`).

## Première fois (une seule fois pour toutes tes installations)

```bash
docker run --rm -v "$PWD":/w -w /w php:8.2-cli php tools/codex-license.php init ./licences
```

Ça crée `licences/license-private.key` (**secrète** : sauvegarde-la hors du serveur, ne la copie nulle part)
et `licences/license-public.key`. Copie la clé **publique** dans `src/license-public.key` de chaque
installation de Codex. Sans ce fichier, aucune clé ne peut être activée.

Si tu perds la clé privée, tu ne peux plus émettre de clés, et il faudrait changer la clé publique
de chaque installation : fais-en une copie de sauvegarde.

## Émettre une clé

```bash
docker run --rm -v "$PWD":/w -w /w php:8.2-cli php tools/codex-license.php issue licences/license-private.key \
  --licensee "Nom du client" --updates-until 2027-10-04 > cle-client.txt
```

`--updates-until` est facultatif (sans lui : droit aux mises à jour sans limite de date). Chaque émission
est ajoutée à `licences/issued-keys.jsonl` (numéro, client, dates) : c'est ta liste des clés en circulation.

## Lier une clé à une installation (recommandé)

Dans l'onglet Premium de Codex, le client voit un **code de demande** (`CDXREQ1.…`) qui identifie son installation.
Il te l'envoie, et tu émets la clé avec :

```bash
... php tools/codex-license.php issue licences/license-private.key --licensee "Nom" --bind "CDXREQ1.…" > cle-client.txt
```

La clé ne s'activera que sur cette installation. Sans `--bind`, elle est valable partout (l'outil te le rappelle).
`request-inspect <code>` affiche le contenu d'un code de demande.

## Vérifier une clé

```bash
docker run --rm -v "$PWD":/w -w /w php:8.2-cli php tools/codex-license.php inspect licences/license-public.key "$(cat cle-client.txt)"
```

## Ce qu'une clé fait, et ne fait pas

- Elle déverrouille l'onglet IA, **définitivement** : rien dans Codex ne le reverrouille.
- La date de fin et la révocation ne concernent que les **mises à jour** (la source qui les distribuera, à venir) :
  une clé échue ou révoquée n'enlève rien de ce qui est déjà installé.
- C'est une licence pour installations honnêtes, pas une protection anti-copie : le code tourne chez le client.
