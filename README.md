## Установка

* В вашем образе php обязательно должна присутствовать библиотека `ext-yaml`!
  
* Поставить пакет для подключения приватных репозиториев: `composer require guest-one/composer-require-ext`
* Выполнить `composer require-ext --dev URL гита`

## Использование

### Док-блоки
Для написания комментариев к полям следует указывать следующий формат в поле description:\
``* `property` comment ``\
Пример:
```yaml
User:
  description: >
    Модель пользователя
      * `id` ID пользователя
      * `email` его почта
  type: object
  properties:
    id:
      type: integer
    email:
      type: string
```
Результат:
```typescript
// Модель пользователя
export interface User {
	id: number, // ID пользователя
	email: string, // его почта
}
```

### Поля required (`field?: type`)
Если не указывать у сущности поле `required`, то автоматически все поля будут обязательны. 
Так же если просто оставить его пустым то все поля станут необязательными

### Генерация в typescript

`php artisan swagger:typescript {path} {output}`:
- `{path}` - это путь до файла сваггера
- `{output}` - куда нужно положить данный файл

Пример: `php artisan swagger:typescript "swagger.yaml" "typescript.ts"`
