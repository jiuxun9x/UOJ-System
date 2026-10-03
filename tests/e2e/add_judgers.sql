USE `app_uoj233`;
-- the judgers that the tests may start besides the one of docker-compose.yml
insert into judger_info (judger_name, password, ip) values ('compose_judger_2', '_judger_password_2_', 'uoj-judger-2');
insert into judger_info (judger_name, password, ip) values ('compose_judger_3', '_judger_password_3_', 'uoj-judger-3');
insert into judger_info (judger_name, password, ip) values ('compose_judger_4', '_judger_password_4_', 'uoj-judger-4');
-- a judger that only exists in the tests that talk to the judge API themselves
insert into judger_info (judger_name, password, ip) values ('e2e_fake_judger', '_fake_judger_password_', '');
