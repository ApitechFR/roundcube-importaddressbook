# Roundcube Webmail ImportAddressBook

Ce plugin permet l'importation d'utilisateurs dans les contacts, à partir d'un fichier CSV, en réutilisant le plus possible du code déjà existant dans Roundcube.

## Addressbook "Global"

Ce plugin fonctionne en complément du plugin `globaladdressbook` https://github.com/johndoh/roundcube-globaladdressbook

Il est d'ailleurs possible de définir l'addressbook `global` comme `adressbook` par défaut ([cf. cette issue Github](https://github.com/johndoh/roundcube-globaladdressbook/issues/49))
```
$config['default_addressbook'] = 'global'
```

## Déclenchement de l'import

Afin d'importer des utilisateurs, il faut être connecté, et avoir les droits en écriture sur l'addressbook ciblé par le plugin.

Il est possible d'utiliser un script afin de générer cette connexion (une seule connexion réussie suffit), par exemple avec [`roundcube-login-check`](https://github.com/sourcepole/roundcube-login-check) ou [`roundcube-login-check-ts`](https://github.com/ApitechFR/roundcube-login-check-ts)
