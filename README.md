# DataTables Server-Side API for Laravel Framework

## Quick Installation
```bash
$ composer require ad-mos/laravel-datatables
```

## Usage examples

1 - Simple table:
```php
public function data(DataTables $dataTables)
{
    return $dataTables->provide(new User);
}
```

2 - Table with joins:
```php
public function data(DataTables $dataTables)
{
    $model = new User;

    $query = $model->newQuery()
        ->leftJoin('user_emails', 'user_emails.user_id', '=', 'users.id')
        ->leftJoin('user_phones', 'user_phones.user_id', '=', 'users.id')
        ->groupBy('users.id');

    $aliases = [
        'emails' => 'GROUP_CONCAT(DISTINCT `user_emails`.email SEPARATOR \'|\')',
        'phones' => 'GROUP_CONCAT(DISTINCT `user_phones`.phone SEPARATOR \'|\')',
    ];

    return $dataTables->provide($this->model, $query, $aliases);
}
```

DataTables can be accessed through IoC, helper or facade:

```php
return $dataTables->provide(...);

return datatables()->provide(...);

return \DataTables::provide(...);
```

## License

The MIT License. More information [here](https://github.com/ad-mos/laravel-datatables/blob/master/LICENSE).
