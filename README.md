# agentdvr-alpr
Use a local alpr for agentdvr plate recognition


Agentdvr does not support a local alpr for recognition.  Platerecognizer is great and a lot more accurate than alpr BUT it is slow as it is an internet resource and it has a cost if doing a lot of queries.

To install this you must have at least a basic understanding of php and web server configuration.

Steps:
1. Install alpr.  Lots of instructions available if you search.  E.g. https://techexpert.tips/openalpr/openalpr-installation-on-ubuntu-linux/

2. Test alpr with a test image - make sure there are no errors

3. You will need to make sure you have php-fpm enabled for your chosen web server.  I have tested with Apache/httpd but there is no reason why this would not work with others - e.g. ngix.  I will not go into installing a web server as you can search for this.

4. Place lpr.php in a path on your web server or the root web directory - e.g. /var/www/html

5. Change the line used to run alpr in the lpr.php file to match your region.  I am in Australia so I use --country auwide.

6. Make the required changes to your php-fpm cofig file to allow the web server user to find alpr.  For me this was located at: /etc/php-fpm.d/www.conf
-> env[PATH] = /usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/usr/lib64/ccache
-> env[LD_LIBRARY_PATH] = /usr/local/lib:/usr/local/lib64
      
- PHP runs in a sandbox when running from a web server so while alpr may work for you as a user, it may not work when running as the web server user.  The [path] variable above  is used for locating alpr 
Similarly, the [LD_LIBRARY_PATH] variable is used for locating all the libraries that alpr need in order to run.  Alpr will error if these libraries cannot be found.
  
7. Make the required changes to the php.ini file to allow post data.  For me this file was located at: /etc/php.ini
-> post_max_size = 8M
-> enable_post_data_reading = On
-> file_uploads = On
-> upload_max_filesize = 8M   

- The post size should be big enough for the images sent from AgentDVR.  2M should be fine but for my testing I upped this.

8. Authentication - There is no authentication coded into the script so be aware and dont expose this website to the internet OR implement a simple authentication token (TODO)

9. Test your alpr works - e.g. curl -F 'upload=@/path/to/car.jpg' http://localhost:8080/lpr.php
- If you have tmp path errors then you may need to manually create a tmp subdirectory below where you place lpr.php.  Make sure you chown this directory to your web server running user.  E.g. sudo chown -R apache:apache ./tmp
- Note: For debugging purposes I have left the images in the tmp directory after processing - they are viewable in the tmp subdirectory.  Once everything is working for you then uncomment the following line.
-> line unlink($filename);
- Note: Make sure your car image file is under the size set in the previous steps

10. Setup AgentDVR to point to your new alpr - in the server config->intelligence set the plate recogniser to
-> http://localhost:8080/lpr.php

I have implemented some logging to help diagnose errors so look in web server error log and the php-fpm error log for clues.
E.g. /var/log/php-fpm/www-errorlog.log and /var/log/httpd/error_log
Note that these may be different if you are on a different distribution or different web server.
