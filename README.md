# BMWConnecteDrive
A PHP Client for BMW Connected Drive API

## Usage
First create a config.json file containing your BMW Connected Drive account details:
```
{
  "vin": "YOURVINDNUMBER",
  "username": "YOUREMAIL",
  "password": "YOURPASSWORD"
}
```

Then instantiate the class:

```
require 'ConnectedDrive.php';

$bmw = new \net\bluewalk\connecteddrive\ConnectedDrive(__DIR__ . '/config.json');
```

## Current functions
* Authenticating
* Get vehicle information
* Get remote services execution status
* Get navigation data